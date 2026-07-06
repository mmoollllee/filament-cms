<?php

use Mmoollllee\Cms\Models\Menu;
use Workbench\App\Models\Tenant;

/**
 * Menu::linksForLocation() caches per tenant+location via rememberForever. These
 * tests pin that editing a menu's STRUCTURE (items/locations) — which does not fire
 * Menu model events — still busts that cache. The testbench's array cache persists
 * within a single test, so a stale read here means the invalidation is missing.
 */

function makeHeaderMenu(Tenant $tenant): Menu
{
    $menu = Menu::create(['name' => 'Header', 'tenant_id' => $tenant->id, 'is_visible' => true]);
    $menu->locations()->create(['location' => 'header', 'tenant_id' => $tenant->id]);
    $menu->menuItems()->create(['title' => 'Start', 'url' => '/', 'order' => 0]);

    return $menu;
}

it('reflects an added menu item without a stale cache', function () {
    $tenant = Tenant::factory()->create();
    $menu = makeHeaderMenu($tenant);

    expect(Menu::linksForLocation('header', $tenant))->toHaveCount(1);

    $menu->menuItems()->create(['title' => 'Über uns', 'url' => '/ueber-uns', 'order' => 1]);

    expect(Menu::linksForLocation('header', $tenant))->toHaveCount(2);
});

it('reflects a deleted menu item without a stale cache', function () {
    $tenant = Tenant::factory()->create();
    $menu = makeHeaderMenu($tenant);
    $extra = $menu->menuItems()->create(['title' => 'Temporär', 'url' => '/tmp', 'order' => 1]);

    expect(Menu::linksForLocation('header', $tenant))->toHaveCount(2);

    $extra->delete();

    expect(Menu::linksForLocation('header', $tenant))->toHaveCount(1);
});

it('reflects a renamed menu item without a stale cache', function () {
    $tenant = Tenant::factory()->create();
    $menu = makeHeaderMenu($tenant);

    expect(collect(Menu::linksForLocation('header', $tenant))->pluck('label'))->toContain('Start');

    $menu->menuItems()->first()->update(['title' => 'Startseite']);

    expect(collect(Menu::linksForLocation('header', $tenant))->pluck('label'))
        ->toContain('Startseite')
        ->not->toContain('Start');
});

it('reflects a newly assigned location without a stale cache', function () {
    $tenant = Tenant::factory()->create();
    $menu = Menu::create(['name' => 'Footer', 'tenant_id' => $tenant->id, 'is_visible' => true]);
    $menu->menuItems()->create(['title' => 'Impressum', 'url' => '/impressum', 'order' => 0]);

    // No location assigned yet → nothing resolves for the footer slot.
    expect(Menu::linksForLocation('footer', $tenant))->toHaveCount(0);

    $menu->locations()->create(['location' => 'footer', 'tenant_id' => $tenant->id]);

    expect(Menu::linksForLocation('footer', $tenant))->toHaveCount(1);
});
