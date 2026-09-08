<?php

/*
 * Renders a content edit form (Livewire) so the OVERRIDDEN builder views
 * (resources/overrides/filament-forms/…) actually compile and the shared
 * BlockBuilder item actions (Block-Optionen, Block kopieren, clipboard paste,
 * cross-builder drag & drop) are evaluated — the panel-side regression net
 * for the vendored views.
 */

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
        // The docs site offers more than one routable type, so the type field renders
        // as the Seiten-Typ select (WHICH types it offers is pinned below).
        ->assertSee('Seiten-Typ')
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

it('offers the Sektion type only on sites that opted in', function () {
    // What the opt-in decides is the OPTION, not the field: every site with more than
    // one routable type renders the select, but only a site whose extension overrides
    // the default.section blueprint with offeredInTypeSelect lets editors pick it.
    // Asserted on the component's options rather than the rendered HTML — a page built
    // from section blocks says "Sektion" all over itself.
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        ->assertSchemaComponentExists(
            'content_type',
            'form',
            fn (Select $select): bool => ! array_key_exists('default.section', $select->getOptions()),
        );

    // Tenant B (site_key 'acme') is the onepager demo — it opted in.
    $tenantB = Tenant::where('site_key', 'acme')->firstOrFail();
    Filament::setTenant($tenantB);
    app(CurrentTenant::class)->set($tenantB);

    $section = Content::where('tenant_id', $tenantB->getKey())->where('path', '/leistungen')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $section->getKey()])
        ->assertOk()
        ->assertSee('Seiten-Typ')
        ->assertSchemaComponentExists(
            'content_type',
            'form',
            fn (Select $select): bool => array_key_exists('default.section', $select->getOptions()),
        );
});
