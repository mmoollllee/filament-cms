<?php

/*
 * Renders a content edit form (Livewire) so the OVERRIDDEN builder views
 * (resources/overrides/filament-forms/…) actually compile and the shared
 * BlockBuilder item actions (Block-Optionen, Block kopieren, clipboard paste,
 * cross-builder drag & drop) are evaluated — the panel-side regression net
 * for the vendored views.
 */

use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $this->tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setTenant($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
});

it('renders the block builder on the content edit form with the cms item actions', function () {
    // The seeded home page: root sections with child blocks (text/media/listing).
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        // The docs site (marketing) is pages-only: the Seite/Sektion choice is NOT
        // enabled for its site_key, the type field stays hidden.
        ->assertDontSee('Seiten-Typ')
        // Pages nest under pages: the parent select is offered.
        ->assertSee('Übergeordnete Seite')
        ->assertSee('Block-Optionen')                                    // shared options action (BlockBuilder)
        ->assertSee('Block kopieren')                                    // copy action (alpineClickHandler)
        ->assertSeeHtml('filament_builder_clipboard')                    // copy JS → clipboard/localStorage
        ->assertSee('Aus Zwischenablage einfügen')                       // paste entry (block-picker override)
        ->assertSeeHtml('data-sortable-group="section-blocks"')          // cross-builder drag & drop (builder override)
        ->assertSeeHtml('transferBuilderItem')                           // …and its Livewire call
        ->assertSee('Block hinzufügen')                                  // add action label
        // Regression: the override header comment must never leak into the page
        // (Blade comments don't nest — a literal token inside terminates early).
        ->assertDontSee('To re-vendor')
        ->assertDontSee('cms features carried');
});

it('offers the Seite/Sektion choice only on sites that opted in', function () {
    // Tenant B (site_key 'acme') is the onepager demo — its site extension
    // overrides the default.section blueprint with offeredInTypeSelect, so the
    // select renders (beside the title).
    $tenantB = Tenant::where('site_key', 'acme')->firstOrFail();
    Filament::setTenant($tenantB);
    app(CurrentTenant::class)->set($tenantB);

    $section = Content::where('tenant_id', $tenantB->getKey())->where('path', '/leistungen')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $section->getKey()])
        ->assertOk()
        ->assertSee('Seiten-Typ')
        ->assertSee('Sektion');
});
