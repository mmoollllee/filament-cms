<?php

namespace Mmoollllee\Cms\Support\Media\References;

use Illuminate\Support\Collection;
use Mmoollllee\Cms\Models\Menu;

/**
 * Files a menu links by their URL — the price list in the footer menu. Shown
 * while the menu is visible, placed in a location and on a tenant guests may
 * enter: then it is on every page that renders it.
 *
 * Read off the tables directly: the menu builder's relations ask its panel
 * plugin for the models, and a scan also runs where no panel is.
 */
final class MenuMediaReferences extends ScansRecords
{
    public static function mediaUsagesForTenants(Collection $tenants): iterable
    {
        $tenantKeys = static::tenantKeys($tenants);

        if ($tenantKeys === []) {
            return [];
        }

        $model = new Menu;
        $connection = $model->getConnection();
        $menus = $connection->table($model->getTable())->whereIn('tenant_id', $tenantKeys)->get()->keyBy('id');

        if ($menus->isEmpty()) {
            return [];
        }

        $items = $connection->table(config('filament-menu-builder.tables.menu_items', 'menu_items'))
            ->whereIn('menu_id', $menus->keys())
            ->whereNotNull('url')
            ->get(['menu_id', 'title', 'url'])
            ->groupBy('menu_id');

        $placed = $connection->table(config('filament-menu-builder.tables.menu_locations', 'menu_locations'))
            ->whereIn('menu_id', $menus->keys())
            ->pluck('menu_id')
            ->flip();

        $scanner = MediaReferenceScanner::make();
        $closed = static::closedTenantKeys($tenants);
        $usages = [];

        foreach ($items as $menuId => $links) {
            $details = [];

            foreach ($links as $link) {
                foreach ($scanner->scan((string) $link->url) as $itemId) {
                    $details[$itemId] ??= (string) $link->title;
                }
            }

            if ($details === []) {
                continue;
            }

            $menu = $menus[$menuId];

            array_push($usages, ...static::usages(
                owner: static::hydrate(Menu::class, $menu),
                label: (string) $menu->name,
                appliedIds: array_keys($details),
                draftIds: [],
                live: (bool) $menu->is_visible && isset($placed[$menuId]) && ! isset($closed[$menu->tenant_id]),
                details: $details,
            ));
        }

        return $usages;
    }
}
