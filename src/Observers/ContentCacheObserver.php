<?php

namespace Mmoollllee\Cms\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;

/**
 * Invalidates and re-warms frontend caches when Content, Menu, or Tenant records change.
 *
 * All frontend caches use `rememberForever` — this observer is the sole invalidation mechanism.
 * After clearing stale keys, it immediately re-populates the cache so the next visitor
 * still gets a cache hit. Wired in CmsServiceProvider::boot() against the config-resolved models.
 */
class ContentCacheObserver
{
    public function contentSaved(Content $content): void
    {
        $this->invalidateContentCaches($content);
    }

    public function contentDeleted(Content $content): void
    {
        $this->invalidateContentCaches($content);
    }

    public function menuSaved(Menu $menu): void
    {
        $this->invalidateMenuCaches($menu);
    }

    public function menuDeleted(Menu $menu): void
    {
        $this->invalidateMenuCaches($menu);
    }

    /**
     * Menu items and locations belong to a Menu but do NOT fire Menu model events,
     * so reordering/adding/removing items (or assigning a location) in the panel
     * would otherwise leave the rememberForever menu-link cache stale. Resolve the
     * owning Menu from the item/location and invalidate its tenant's caches.
     */
    public function menuStructureChanged(Model $itemOrLocation): void
    {
        $menu = $this->resolveOwningMenu($itemOrLocation);

        if ($menu !== null) {
            $this->invalidateMenuCaches($menu);
        }
    }

    public function tenantSaved(Tenant $tenant): void
    {
        $this->invalidateTenantCache($tenant);
    }

    /**
     * Without this, a deleted tenant's domain keeps serving from the warm
     * rememberForever cache instead of falling through to the 404 pipeline.
     */
    public function tenantDeleted(Tenant $tenant): void
    {
        Cache::forget(CacheKeys::tenantDomain($tenant->primary_domain));
    }

    protected function invalidateContentCaches(Content $content): void
    {
        $tenantId = $content->tenant_id;

        if ($tenantId === null) {
            return;
        }

        // Clear the specific content path cache
        if (filled($content->path)) {
            Cache::forget(CacheKeys::content($tenantId, $content->path));
        }

        // A rename changes the `path` column; the OLD path's rememberForever cache (a cached
        // Content or a cached null) must be dropped too — otherwise the old URL keeps serving
        // stale content forever and never falls through to the redirect/404 pipeline. Mirrors
        // the primary_domain old-value handling in invalidateTenantCache().
        if ($content->wasChanged('path') && filled($content->getOriginal('path'))) {
            Cache::forget(CacheKeys::content($tenantId, $content->getOriginal('path')));
        }

        // Clear sections and sitemap (content changes may affect these)
        Cache::forget(CacheKeys::sections($tenantId));
        Cache::forget(CacheKeys::sitemap($tenantId));

        // Redirect suggestion candidates are derived from live content — drop the list so it
        // rebuilds lazily on the next 404 callback.
        Cache::forget(CacheKeys::candidates($tenantId));

        // A redirect can target a content path (to_content_id → resolvedPath()); a content change
        // may re-point it. Forget (don't eagerly rebuild) the active-redirect map so a bulk
        // save/reorder of N rows doesn't rebuild it N times; it rebuilds lazily on the next
        // request, matching how the content/sections caches above are handled.
        app(RedirectResolver::class)->forgetById($tenantId);
    }

    protected function invalidateMenuCaches(Menu $menu): void
    {
        $tenantId = $menu->tenant_id;

        if ($tenantId === null) {
            return;
        }

        $locations = array_keys(Cms::menuLocations());

        foreach ($locations as $location) {
            Cache::forget(CacheKeys::menu($tenantId, $location));
        }

        // Re-warm menu caches
        $tenant = $menu->tenant;

        if ($tenant !== null) {
            foreach ($locations as $location) {
                Menu::linksForLocation($location, $tenant);
            }
        }
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

    protected function invalidateTenantCache(Tenant $tenant): void
    {
        // Clear cached tenant by current domain
        Cache::forget(CacheKeys::tenantDomain($tenant->primary_domain));

        // If the domain changed, also clear the old domain's cache
        if ($tenant->wasChanged('primary_domain') && $tenant->getOriginal('primary_domain') !== null) {
            Cache::forget(CacheKeys::tenantDomain($tenant->getOriginal('primary_domain')));
        }

        // Re-warm the tenant domain cache
        Cache::rememberForever(
            CacheKeys::tenantDomain($tenant->primary_domain),
            fn () => $tenant->fresh(),
        );

        // site_key selects each content's blueprint, so every resolvedPath()-derived cache
        // (content paths, sections, sitemap, redirect map + suggestion paths) is stale when it
        // changes. Rare, but forget them here so the site stays correct without a manual clear.
        if ($tenant->wasChanged('site_key')) {
            $tenantId = $tenant->getKey();

            Cache::forget(CacheKeys::sections($tenantId));
            Cache::forget(CacheKeys::sitemap($tenantId));
            Cache::forget(CacheKeys::candidates($tenantId));
            Cache::forget(CacheKeys::redirects($tenantId));

            Cms::contentModel()::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('path')
                ->pluck('path')
                ->each(fn (string $path) => Cache::forget(CacheKeys::content($tenantId, $path)));
        }
    }
}
