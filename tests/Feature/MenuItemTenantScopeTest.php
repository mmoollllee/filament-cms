<?php

/*
 * `tenant_id` lives on `menus`, not on `menu_items` — an item's tenant is only
 * implied by its menu_id. Filament's tenancy scope reaches Menu (it backs a
 * resource) but never MenuItem, and the menu-builder plugin ships no policy, so
 * its Livewire component fed client-supplied ids straight into unfiltered
 * queries: reorder() mass-updates whereIn('id', $order), edit/delete resolve by
 * bare id. A tenant editor could read, re-parent, rewrite and delete another
 * tenant's menu items from their own menu page.
 *
 * The global scope registered in CmsServiceProvider closes that. These tests
 * drive the model layer the plugin uses, so they hold regardless of how the
 * plugin's UI evolves.
 */

use Datlechin\FilamentMenuBuilder\Models\MenuItem;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    $this->foreignTenant = Tenant::factory()->create([
        'site_key' => 'default',
        'primary_domain' => 'foreign-menu.test',
    ]);

    $ownMenu = Menu::create(['name' => 'Eigenes', 'tenant_id' => $this->tenant->getKey(), 'is_visible' => true]);
    $foreignMenu = Menu::create(['name' => 'Fremdes', 'tenant_id' => $this->foreignTenant->getKey(), 'is_visible' => true]);

    $this->own = $ownMenu->menuItems()->create(['title' => 'Eigener Punkt', 'url' => '/eigen', 'order' => 1]);
    $this->foreign = $foreignMenu->menuItems()->create(['title' => 'Fremder Punkt', 'url' => '/fremd', 'order' => 1]);
});

it('lists only the current tenant menu items', function () {
    $ids = MenuItem::query()->pluck('id')->all();

    expect($ids)->toContain($this->own->id)
        ->and($ids)->not->toContain($this->foreign->id);
});

it('cannot read another tenant menu item by id', function () {
    expect(MenuItem::query()->find($this->foreign->id))->toBeNull()
        ->and(MenuItem::query()->find($this->own->id))->not->toBeNull();
});

it('cannot mass-update another tenant menu item', function () {
    // Mirrors MenuItemService::updateOrder(), the shape reorder() calls with
    // client-supplied ids — whereHas() would NOT survive this, whereIn does.
    MenuItem::query()
        ->whereIn('id', [$this->own->id, $this->foreign->id])
        ->update(['parent_id' => null, 'order' => 99]);

    expect($this->own->fresh()->order)->toBe(99)
        ->and($this->foreign->fresh()->order)->toBe(1);
});

it('cannot delete another tenant menu item', function () {
    MenuItem::query()->whereIn('id', [$this->foreign->id])->delete();

    // Read the foreign row unscoped — the scope would otherwise hide a deletion.
    expect(MenuItem::withoutGlobalScope('cms_tenant')->find($this->foreign->id))->not->toBeNull();
});

it('scopes to whichever tenant is current', function () {
    app(CurrentTenant::class)->set($this->foreignTenant);

    $ids = MenuItem::query()->pluck('id')->all();

    expect($ids)->toContain($this->foreign->id)
        ->and($ids)->not->toContain($this->own->id);
});

it('stays inert without a resolved tenant so console and queue keep working', function () {
    app(CurrentTenant::class)->forget();

    $ids = MenuItem::query()->pluck('id')->all();

    expect($ids)->toContain($this->own->id)
        ->and($ids)->toContain($this->foreign->id);
});
