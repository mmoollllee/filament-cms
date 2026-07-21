<?php

namespace Mmoollllee\Cms\Models;

use Datlechin\FilamentMenuBuilder\Models\Menu as BaseMenu;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Preview\PreviewMode;

/**
 * Tenant-scoped navigation menu (extends the datlechin menu-builder model).
 * Shared infrastructure model; the `tenant_id` column + base `menus` table are
 * provided by app/datlechin migrations.
 */
class Menu extends BaseMenu
{
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
     * @return array<int, array{path: string, href: string, label: string}>
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

                return $menu->menuItems
                    ->map(fn ($item): array => [
                        'path' => $item->url ?? '/',
                        'href' => $item->url ?? '/',
                        'label' => $item->title,
                    ])
                    ->all();
            }),
        );
    }
}
