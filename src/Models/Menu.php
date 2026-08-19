<?php

namespace Mmoollllee\Cms\Models;

use Blendbyte\FilamentResourceLock\Models\Concerns\HasLocks;
use Datlechin\FilamentMenuBuilder\Models\Menu as BaseMenu;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Content\PayloadLink;
use Mmoollllee\Cms\Support\Preview\PreviewMode;

/**
 * Tenant-scoped navigation menu (extends the datlechin menu-builder model).
 * Shared infrastructure model; the `tenant_id` column + base `menus` table are
 * provided by app/datlechin migrations.
 */
class Menu extends BaseMenu
{
    use HasLocks;

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Cms::tenantModel());
    }

    /**
     * Resolve a menu by location scoped to a specific tenant.
     */
    public static function locationForTenant(string $location, Tenant $tenant): ?self
    {
        return self::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_visible', true)
            ->whereRelation('locations', 'location', $location)
            ->with('menuItems.linkable')
            ->first();
    }

    /**
     * Get menu items as navigation link arrays for a given location and tenant.
     *
     * Every entry carries the item's own presentation metadata alongside the
     * resolved URL. Without it a consumer can only infer how an entry wants to
     * be rendered, and the obvious inference — "the host differs, so it must be
     * the cross-site call to action" — silently claims every future external
     * link a site adds. Editors set target/rel/classes/icon per item in the
     * menu builder; this is what carries their choice to the frontend.
     *
     * `icon` is a free-text field there, so it is passed through unvalidated:
     * render it via <x-site.menu-icon>, which drops an unknown name
     * instead of throwing SvgNotFound on every page holding the menu.
     *
     * @return array<int, array{path: string, href: string, label: string, target: ?string, rel: ?string, classes: ?string, icon: ?string}>
     */
    public static function linksForLocation(string $location, Tenant $tenant): array
    {
        return Cache::rememberForever(
            CacheKeys::menu($tenant->getKey(), $location),
            // bypass(): the linkable Content models resolve item URLs — built
            // during a preview request they would freeze DRAFT paths into this
            // guest-served forever cache.
            fn (): array => app(PreviewMode::class)->bypass(function () use ($location, $tenant): array {
                $menu = self::locationForTenant($location, $tenant);

                if ($menu === null) {
                    return [];
                }

                // SECURITY: the menu-builder plugin stores `url` as a plain
                // TextInput with no scheme validation, so a javascript:/data:
                // value would land in the header, footer and flyout href of
                // every page. Scheme-check here — one place covers all menu
                // consumers — falling back to '/' so a rejected item still
                // renders as a link instead of silently leaving the navigation.
                return $menu->menuItems
                    ->map(fn ($item): array => [
                        'path' => PayloadLink::safeUrl($item->url) ?? '/',
                        'href' => PayloadLink::safeUrl($item->url) ?? '/',
                        'label' => $item->title,
                        // Presentation metadata, editor-owned. `target` is cast to
                        // the menu-builder's LinkTarget enum and carries that
                        // plugin's column default ('_self') when the editor set
                        // none — passed through rather than second-guessed here.
                        'target' => $item->target?->value,
                        'rel' => $item->rel,
                        'classes' => $item->classes,
                        'icon' => $item->icon,
                    ])
                    ->all();
            }),
        );
    }
}
