<?php

/*
 * Layout of the "Inhalt" tab. A type WITH a block builder gets the builder (2/3)
 * beside a sidebar (1/3) carrying the structure fields and the collapsed "Meta"
 * (SEO) section. A builder-less type (category/listing page) has nothing tall to
 * put a sidebar next to: page header, detail sections, structure fields and Meta
 * stack full width instead of leaving a mostly empty column beside a single
 * header section.
 */

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\Tenant as TenantContract;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Filament\Pages\Dashboard;
use Workbench\App\Models\Tenant;

/**
 * A content resource whose form blueprint is the given one. Only the blueprint
 * LOOKUP is stubbed — the layout decision still runs through the real hooks
 * (contentTabHasBuilder(), formIsRoutable()).
 *
 * @return class-string<TenantScopedContentResource>
 */
function layoutResource(ContentBlueprint $blueprint): string
{
    $resource = new class extends TenantScopedContentResource
    {
        public static ?ContentBlueprint $formBlueprint = null;

        public static function inhaltTab(?TenantContract $tenant): Tab
        {
            return self::contentTab($tenant);
        }

        protected static function resolveFormBlueprint(): ?ContentBlueprint
        {
            return self::$formBlueprint;
        }
    };

    $resource::$formBlueprint = $blueprint;

    return $resource::class;
}

/**
 * The component's children, including hidden ones: this pins the schema TREE,
 * not what a given record happens to show. Components reach their child schema
 * through the container, which only a rendered form gives them — a bare one is
 * enough here, as skipping the visibility filter keeps Livewire out of it.
 *
 * @return array<int, Component>
 */
function childComponentsOf(Component $component): array
{
    $component->container(Schema::make(app(Dashboard::class)));

    return $component->getChildSchema()?->getComponents(withHidden: true) ?? [];
}

/**
 * A listing/category type: routable, but with no block builder.
 */
function builderlessBlueprint(): ContentBlueprint
{
    return new class extends ConfiguredContentBlueprint
    {
        protected string $key = 'test.kategorie';

        protected bool $hasBuilder = false;
    };
}

/**
 * The "Meta" section among the given components, if it sits directly among them.
 *
 * @param  array<int, Component>  $components
 */
function metaSectionAmong(array $components): ?Section
{
    foreach ($components as $component) {
        if ($component instanceof Section && $component->getHeading() === 'Meta') {
            return $component;
        }
    }

    return null;
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['site_key' => 'default', 'primary_domain' => 'layout.test']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('stacks Meta full width in the Inhalt tab when the type has no block builder', function () {
    $children = childComponentsOf(layoutResource(builderlessBlueprint())::inhaltTab($this->tenant));

    // Meta is a direct child of the tab, so it spans the tab's full width…
    expect(metaSectionAmong($children))->not->toBeNull();

    // …and no column grid hides it in a sidebar.
    $gridsCarryingMeta = array_filter(
        $children,
        fn ($component): bool => $component instanceof Grid && metaSectionAmong(childComponentsOf($component)) !== null,
    );

    expect($gridsCarryingMeta)->toBe([]);
});

it('keeps the structure fields above the Meta section for a builder-less type', function () {
    $children = childComponentsOf(layoutResource(builderlessBlueprint())::inhaltTab($this->tenant));

    $metaIndex = array_search(metaSectionAmong($children), $children, strict: true);
    $structureIndex = array_search(
        current(array_filter($children, fn ($component): bool => $component instanceof Grid)),
        $children,
        strict: true,
    );

    expect($structureIndex)->toBeInt()
        ->and($metaIndex)->toBeGreaterThan($structureIndex);
});

it('keeps Meta in the sidebar beside the block builder', function () {
    $blueprint = new class extends ConfiguredContentBlueprint
    {
        protected string $key = 'test.seite';
    };

    $children = childComponentsOf(layoutResource($blueprint)::inhaltTab($this->tenant));

    // Not full width: it lives one level deeper, in the sidebar column of the
    // builder/sidebar grid.
    expect(metaSectionAmong($children))->toBeNull();

    $sidebarMeta = null;

    foreach ($children as $child) {
        if (! $child instanceof Grid) {
            continue;
        }

        foreach (childComponentsOf($child) as $column) {
            if ($column instanceof Grid) {
                $sidebarMeta ??= metaSectionAmong(childComponentsOf($column));
            }
        }
    }

    expect($sidebarMeta)->not->toBeNull();
});
