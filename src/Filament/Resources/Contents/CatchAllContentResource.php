<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents;

use BackedEnum;
use Closure;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Resources\Concerns\RendersPageHeader;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ListContents;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * The catch-all "Seiten" resource: manages every content type of the current tenant's
 * site that is NOT claimed by a more specific site resource. Concrete + registered by
 * apps directly (no subclass) — the model is resolved via Cms::contentModel(),
 * and the opt-in page-header section via Cms::enableContentPageHeader().
 */
class CatchAllContentResource extends TenantScopedContentResource
{
    use RendersPageHeader {
        pageHeaderSection as protected pageHeaderSectionFields;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $slug = 'contents';

    // Pages nest under pages: a ?parent=… query param scopes the list (and the
    // create action) to that parent record.
    protected static bool $supportsParentScopedListing = true;

    /**
     * The catch-all page header is opt-in per app via Cms::enableContentPageHeader()
     * (mirrors how site resources `use RendersPageHeader` unconditionally).
     */
    protected static function pageHeaderSection(?Tenant $tenant): ?Section
    {
        return Cms::hasContentPageHeader()
            ? static::pageHeaderSectionFields($tenant)
            : null;
    }

    /**
     * Returns content types NOT managed by any other specialized resource.
     */
    public static function getContentTypes(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant === null) {
            return [];
        }

        $allBlueprintKeys = array_map(
            fn ($blueprint) => $blueprint->key(),
            app(ContentBlueprintRegistry::class)->forSite($tenant->site_key),
        );

        $managedByOtherResources = [];

        foreach (app(SiteExtensionRegistry::class)->forSite($tenant->site_key) as $extension) {
            foreach ($extension->resources() as $resourceClass) {
                if ($resourceClass === static::class) {
                    continue;
                }

                if (! is_subclass_of($resourceClass, TenantScopedContentResource::class)) {
                    continue;
                }

                foreach ($resourceClass::getContentTypes() as $type) {
                    $managedByOtherResources[] = $type;
                }
            }
        }

        return array_values(array_diff($allBlueprintKeys, $managedByOtherResources));
    }

    /**
     * The multi-type form is the shared {@see TenantScopedContentResource::tabbedForm()} —
     * only the blueprint gating differs: it reacts to the content type the user SELECTS
     * (the closure hooks below) instead of a per-resource static blueprint.
     */
    protected static function formSupportsTeasers(): Closure
    {
        return fn (Get $get): bool => (bool) static::selectedBlueprint(static::resolveSelectedContentType($get))?->supportsTeasers();
    }

    protected static function formShowsPayloadEditor(): Closure
    {
        return fn (Get $get): bool => (bool) static::selectedBlueprint(static::resolveSelectedContentType($get))?->showsPayloadEditor();
    }

    protected static function formIsRoutable(): Closure
    {
        return fn (Get $get): bool => static::selectedBlueprint(static::resolveSelectedContentType($get))?->isRoutable() ?? true;
    }

    /**
     * The multi-type form keeps the raw payload editor in the tree and toggles it
     * reactively per selected type ({@see formShowsPayloadEditor()}). None of the
     * catch-all's types define structured `payload.*` fields, so there is no collision.
     */
    protected static function formIncludesPayloadEditor(): bool
    {
        return true;
    }

    /** The catch-all always offers the block builder, regardless of the selected type. */
    protected static function contentTabHasBuilder(): bool
    {
        return true;
    }

    /**
     * Reactive detail sections for the multi-type catch-all: the selected type's
     * structured payload fields (empty for types that define none), rendered below the
     * builder in the Inhalt tab. The generic raw payload editor is the separate opt-in
     * {@see rawPayloadSection()}.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected static function detailSections(?Tenant $tenant): array
    {
        return [
            Section::make('Details')
                ->columns(2)
                ->visible(fn (Get $get): bool => (static::selectedBlueprint(static::resolveSelectedContentType($get))?->payloadFormComponents() ?? []) !== [])
                ->schema(fn (Get $get): array => static::selectedBlueprint(static::resolveSelectedContentType($get))?->payloadFormComponents() ?? []),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContents::route('/'),
            'create' => CreateContent::route('/create'),
            'edit' => EditContent::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Seiten';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Seiten';
    }

    public static function getModelLabel(): string
    {
        return 'Seite';
    }
}
