<?php

namespace Mmoollllee\Cms\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\ModelCache;
use Mmoollllee\Cms\Support\TenantCache;

/**
 * Keeps the frontend caches in step with the Content, Menu and Tenant writes that change
 * what a visitor sees — once those writes have committed.
 *
 * Every such write moves its tenant to a new cache generation after its transaction
 * commits ({@see TenantCache}): pages, sections, sitemap, suggestion candidates, the
 * redirect map and the menus of that tenant rebuild on their next read, from committed
 * rows. Nothing is forgotten or re-warmed inside the transaction any more — a visitor
 * arriving in that window used to put the old row straight back into the cache, and a
 * rollback used to leave re-warmed caches holding rows that never existed.
 *
 * The host → tenant lookup is the one cache outside the generation (it is how the tenant
 * is found to begin with). It is forgotten by domain and re-warmed, also after commit, from
 * domains captured when the write happened: once the transaction has committed, the model
 * reports its new values as its original ones.
 *
 * Wired in CmsServiceProvider::boot() against the config-resolved models.
 */
class ContentCacheObserver
{
    public function contentSaved(Content $content): void
    {
        // Draft stashes never reach this observer: HasDraft persists the
        // `draft` column via a query-builder update without model events, so
        // every `saved` seen here is a real (applied) content change.
        $this->flushAfterCommit($content, $content->tenant_id);
    }

    public function contentDeleted(Content $content): void
    {
        $this->flushAfterCommit($content, $content->tenant_id);
    }

    public function menuSaved(Menu $menu): void
    {
        $this->flushAfterCommit($menu, $menu->tenant_id);
    }

    public function menuDeleted(Menu $menu): void
    {
        $this->flushAfterCommit($menu, $menu->tenant_id);
    }

    /**
     * Menu items and locations belong to a Menu but do NOT fire Menu model events,
     * so reordering/adding/removing items (or assigning a location) in the panel
     * would otherwise leave the menu-link cache stale. Resolve the owning Menu from
     * the item/location and move its tenant's generation on.
     */
    public function menuStructureChanged(Model $itemOrLocation): void
    {
        $menu = $this->resolveOwningMenu($itemOrLocation);

        if ($menu !== null) {
            $this->flushAfterCommit($itemOrLocation, $menu->tenant_id);
        }
    }

    public function tenantSaved(Tenant $tenant): void
    {
        $tenantId = $tenant->getKey();
        $domain = $tenant->primary_domain;
        // Captured now: after the commit, getOriginal() already answers with the new value.
        $previousDomain = $tenant->wasChanged('primary_domain') ? $tenant->getOriginal('primary_domain') : null;

        $tenant->getConnection()->afterCommit(function () use ($tenant, $tenantId, $domain, $previousDomain): void {
            if (filled($previousDomain)) {
                Cache::forget(CacheKeys::tenantDomain($previousDomain));
            }

            if (filled($domain)) {
                Cache::forget(CacheKeys::tenantDomain($domain));

                // Re-warmed from the committed row (scalar attribute payload, see ModelCache).
                Cache::rememberForever(
                    CacheKeys::tenantDomain($domain),
                    fn (): ?array => ModelCache::pack($tenant->fresh()),
                );
            }

            // site_key selects each content's blueprint and the settings shape what the
            // pages render around: every cache derived from the tenant moves on with it.
            app(TenantCache::class)->flush($tenantId);
        });
    }

    /**
     * Without this, a deleted tenant's domain keeps serving from the warm
     * host lookup instead of falling through to the 404 pipeline.
     */
    public function tenantDeleted(Tenant $tenant): void
    {
        $tenantId = $tenant->getKey();
        $domain = $tenant->primary_domain;

        $tenant->getConnection()->afterCommit(function () use ($tenantId, $domain): void {
            if (filled($domain)) {
                Cache::forget(CacheKeys::tenantDomain($domain));
            }

            app(TenantCache::class)->flush($tenantId);
        });
    }

    protected function flushAfterCommit(Model $model, int|string|null $tenantId): void
    {
        app(TenantCache::class)->flushAfterCommit($model, $tenantId);
    }

    /**
     * Resolve the owning Menu of a menu item/location by its `menu_id`. Queries via
     * the package Menu model (which carries `tenant_id`), independent of the
     * menu-builder plugin's model resolution. Returns null if the menu is gone
     * (e.g. a cascade delete already removed it — the Menu observer covers that).
     */
    protected function resolveOwningMenu(Model $itemOrLocation): ?Menu
    {
        $menuId = $itemOrLocation->getAttribute('menu_id');

        return $menuId === null ? null : Menu::query()->find($menuId);
    }
}
