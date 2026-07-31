<?php

/*
 * The menu builder's "Inhalte" panel is fed by the Content model's MenuPanelable
 * implementation (ProvidesMenuPanel). These tests drive the plugin's own
 * ModelMenuPanel/MenuItem instead of the trait methods directly, so they keep
 * holding across plugin upgrades — v1.0.4 renamed the whole contract
 * (getMenuPanelTitleColumn/UrlUsing/ModifyQueryUsing → getMenuPanelTitle/Url,
 * plus the optional getMenuPanelQuery), which the old per-app implementations
 * silently no longer satisfied.
 */

use Datlechin\FilamentMenuBuilder\MenuPanel\ModelMenuPanel;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'site_key' => 'marketing',
        'primary_domain' => 'panel-own.test',
    ]);

    $this->foreignTenant = Tenant::factory()->create([
        'primary_domain' => 'panel-foreign.test',
    ]);

    app(CurrentTenant::class)->set($this->tenant);
});

function contentMenuPanelItems(): array
{
    return ModelMenuPanel::make('Inhalte')
        ->model(Content::class)
        ->getItems();
}

it('offers the routable contents of the current tenant as menu items', function () {
    $page = Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Über uns',
    ]);

    $items = contentMenuPanelItems();

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toBe('Über uns')
        ->and($items[0]['linkable_id'])->toBe($page->id);
});

it('never offers another tenant content', function () {
    Content::factory()->create([
        'tenant_id' => $this->foreignTenant->id,
        'content_type' => 'default.page',
        'title' => 'Fremde Seite',
    ]);

    expect(contentMenuPanelItems())->toBe([]);
});

it('never offers a project content type by default', function () {
    // Project types (machines, job ads, articles) are opted in per app via
    // menuPanelContentTypes() — the picker is unpaginated, so a type with many
    // records would bury the pages a navigation is actually built from.
    Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.machine',
        'title' => 'Maschine',
        'path' => '/maschinen/bagger',
    ]);

    expect(contentMenuPanelItems())->toBe([]);
});

it('never offers a non-routable content', function () {
    // marketing.note is non-routable: no path, so there is no URL to link to.
    Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.note',
        'title' => 'Notiz',
    ]);

    expect(contentMenuPanelItems())->toBe([]);
});

it('resolves a linked menu item url from the content, following renames', function () {
    $page = Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Über uns',
        'slug' => 'ueber-uns',
    ]);

    $menu = Menu::create(['name' => 'Header', 'tenant_id' => $this->tenant->id, 'is_visible' => true]);
    $menu->locations()->create(['location' => 'header', 'tenant_id' => $this->tenant->id]);
    $item = $menu->menuItems()->create([
        'title' => 'Über uns',
        'linkable_type' => $page->getMorphClass(),
        'linkable_id' => $page->getKey(),
        'order' => 0,
    ]);

    expect($item->fresh()->url)->toBe('/ueber-uns')
        ->and(Menu::linksForLocation('header', $this->tenant))
        ->toBe([['path' => '/ueber-uns', 'href' => '/ueber-uns', 'label' => 'Über uns']]);

    // Moving the page (path is the source of truth) must not leave a stale link.
    $page->update(['title' => 'Unternehmen', 'path' => '/unternehmen']);

    expect($item->fresh()->url)->toBe('/unternehmen');
});
