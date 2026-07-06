<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents;

use BackedEnum;
use Blendbyte\FilamentTitleWithSlug\TitleWithSlugInput;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Fields\PublishingFields;
use Mmoollllee\Cms\Fields\SeoFields;
use Mmoollllee\Cms\Filament\Forms\BlockBuilder;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

abstract class TenantScopedContentResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static \UnitEnum|string|null $navigationGroup = 'Inhalt';

    protected static ?string $recordTitleAttribute = 'title';

    /** @var array<int, string> */
    protected static array $contentTypes = [];

    /** @var array<int, string> */
    protected static array $siteKeys = [];

    protected static bool $supportsParentScopedListing = false;

    // -------------------------------------------------------------------------
    //  Form
    // -------------------------------------------------------------------------

    public static function getModel(): string
    {
        return Cms::contentModel();
    }

    public static function form(Schema $schema): Schema
    {
        return static::tabbedForm($schema, app(CurrentTenant::class)->get());
    }

    /**
     * Standard tabbed form layout. Resources customize via hook methods.
     *
     * Tabs:
     * 1. "Details" (optional) — shown when blueprint has payload fields
     * 2. "Inhalt" (optional) — shown when blueprint has builder
     * 3. "Teaser" (conditional) — shown when blueprint supports teasers and has_teaser is enabled
     * 4. "Veröffentlichung" / "Einstellungen" — always shown
     */
    protected static function tabbedForm(Schema $schema, ?Tenant $tenant): Schema
    {
        $supportsTeasers = static::formSupportsTeasers();

        // "Inhalt" always leads: it hosts the block builder (or, for builder-less types,
        // the detail sections) beside a sidebar carrying the structure fields + Meta.
        $tabs = [
            static::contentTab($tenant),
            Tab::make('Teaser')
                ->icon(Heroicon::OutlinedStar)
                ->visible(fn (Get $get): bool => $supportsTeasers($get) && (bool) $get('payload.has_teaser'))
                ->schema([
                    Section::make('Teaser-Inhalt')
                        ->description('Diese Blöcke werden auf dem Onepager als Teaser angezeigt.')
                        ->schema([static::teaserBuilderField($tenant)]),
                ]),
            static::settingsTab($tenant, $supportsTeasers),
        ];

        return $schema->components([
            ...static::titleRowComponents($tenant),
            ...static::beforeTabs($tenant),
            Tabs::make(static::getModelLabel())
                ->contained(false)
                ->tabs($tabs)
                ->columnSpanFull(),
            // Opt-in raw payload editor, collapsed at the very end of the page.
            static::rawPayloadSection()
                ->visible(static::formShowsPayloadEditor()),
        ]);
    }

    /**
     * Whether the form's content type supports teasers, as a Get-aware closure.
     * Static per resource by default (single-type resources); the multi-type
     * catch-all overrides this to react to the selected content type.
     */
    protected static function formSupportsTeasers(): Closure
    {
        $supportsTeasers = static::resolveFormBlueprint()?->supportsTeasers() ?? false;

        return fn (Get $get): bool => $supportsTeasers;
    }

    /**
     * Whether the form shows the opt-in raw payload editor, as a Get-aware
     * closure. Same static-vs-reactive split as {@see formSupportsTeasers()}.
     */
    protected static function formShowsPayloadEditor(): Closure
    {
        $showsPayloadEditor = static::resolveFormBlueprint()?->showsPayloadEditor() ?? false;

        return fn (Get $get): bool => $showsPayloadEditor;
    }

    /**
     * The "Einstellungen" tab (formerly "Veröffentlichung"): a "Sichtbarkeit" section
     * (status + publishing window + visibility) above a "Darstellung" section
     * (template, layout preset, teaser mode).
     *
     * @param  Closure(Get): bool  $supportsTeasers
     */
    protected static function settingsTab(?Tenant $tenant, Closure $supportsTeasers): Tab
    {
        return Tab::make('Einstellungen')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->schema([
                Section::make('Sichtbarkeit')
                    ->description('Status, Veröffentlichungszeitraum und Sichtbarkeit.')
                    ->columns(2)
                    ->schema(static::publishingFields()),
                Section::make('Darstellung')
                    ->description('Template, Layout und Teaser-Modus.')
                    ->columns(2)
                    ->schema([
                        static::templateField(),
                        static::layoutPresetField($tenant),
                        static::teaserToggleField()
                            ->visible($supportsTeasers),
                    ]),
            ]);
    }

    /**
     * The "Inhalt" tab. For types with a block builder: the page header + the builder
     * (2/3) beside a sidebar (1/3) carrying the structure fields and the collapsed
     * "Meta" (SEO) section, with any full-width detail sections below. For builder-less
     * types (e.g. a listing/category page): the detail sections become the main column
     * beside the same structure + Meta sidebar. Shared by the dedicated resources and
     * the catch-all so the content area looks and behaves identically.
     */
    protected static function contentTab(?Tenant $tenant): Tab
    {
        $hasBuilder = static::contentTabHasBuilder();
        $pageHeader = static::pageHeaderSection($tenant);
        $detailSections = static::detailSections($tenant);

        $sidebar = static::contentSidebar($tenant);

        // The sidebar always carries the Meta section, so it is effectively never empty;
        // the reactive span still lets the main column reclaim the full width should a
        // resource strip the sidebar entirely. getChildComponents() returns only the
        // currently visible children.
        $sidebarHasVisibleContent = static fn (): bool => $sidebar->getChildComponents() !== [];
        $mainSpan = static fn (): array => $sidebarHasVisibleContent()
            ? ['default' => 1, 'xl' => 2]
            : ['default' => 1, 'xl' => 3];

        if ($hasBuilder) {
            return Tab::make('Inhalt')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    ...($pageHeader !== null ? [$pageHeader] : []),
                    Grid::make(['default' => 1, 'xl' => 3])
                        ->schema([
                            Section::make('Inhalts-Blöcke')
                                ->description('Diese Blöcke bilden den Inhalt der Seite')
                                ->contained(false)
                                ->columnSpan($mainSpan)
                                ->schema([static::builderField($tenant)]),
                            $sidebar->visible($sidebarHasVisibleContent),
                        ]),
                    ...$detailSections,
                ]);
        }

        // Builder-less: the page header + detail sections are the main column.
        $main = Grid::make(1)
            ->columnSpan($mainSpan)
            ->schema([
                ...($pageHeader !== null ? [$pageHeader] : []),
                ...$detailSections,
            ]);

        return Tab::make('Inhalt')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema([
                Grid::make(['default' => 1, 'xl' => 3])
                    ->schema([$main, $sidebar->visible($sidebarHasVisibleContent)]),
            ]);
    }

    /**
     * Whether the "Inhalt" tab renders the block builder. Driven by the resolved
     * blueprint; the catch-all overrides this to always show the builder.
     */
    protected static function contentTabHasBuilder(): bool
    {
        return static::resolveFormBlueprint()?->hasBuilder() ?? true;
    }

    /**
     * The "Inhalt"-tab sidebar: the resource's structure/attribute fields (as returned
     * by {@see sidebarFields()} — bare fields or grouped sections, the resource's
     * choice) above the collapsed "Meta" (SEO) section. This is the single home for the
     * Meta section across all content resources.
     */
    protected static function contentSidebar(?Tenant $tenant): Grid
    {
        return Grid::make(1)
            ->columnSpan(['default' => 1, 'xl' => 1])
            ->schema([
                ...static::sidebarFields($tenant),
                static::metaSection(),
            ]);
    }

    /**
     * Opt-in page header shown above the content blocks. Default: none.
     *
     * A resource enables it via the RendersPageHeader
     * trait (or by overriding this method). Keeping the default empty makes the
     * page-header field set a per-project choice rather than a baked-in part of
     * the shared base — so projects that have no page header simply get none.
     */
    protected static function pageHeaderSection(?Tenant $tenant): ?Section
    {
        return null;
    }

    // -------------------------------------------------------------------------
    //  Composable Field Groups
    // -------------------------------------------------------------------------

    /**
     * Publishing fields: visibility, sort, publish_from, publish_until.
     *
     * @return array<int, Component>
     */
    protected static function publishingFields(?string $contentType = null): array
    {
        return PublishingFields::make()
            ->defaultVisibilityUsing(
                fn (Get $get): string => static::getDefaultVisibility($contentType ?? static::resolveSelectedContentType($get))
            )
            ->toArray();
    }

    /**
     * Structure fields for the sidebar: parent_id.
     *
     * The parent_id Select auto-hides when the selected content type has no
     * allowed parent types. The content_type backing field lives in beforeTabs()
     * so it's always rendered, even when a subclass fully replaces sidebarFields().
     *
     * @return array<int, Component>
     */
    protected static function structureFields(?Tenant $tenant): array
    {
        return [
            Select::make('parent_id')
                ->label('Übergeordnete Seite')
                ->options(fn (Get $get, ?Model $record): array => static::getParentOptions($tenant, static::resolveSelectedContentType($get), $record))
                ->default(fn (Get $get): ?int => static::getDefaultParentId($tenant, static::resolveSelectedContentType($get)))
                ->hidden(fn (Get $get): bool => static::getAllowedParentTypes(static::resolveSelectedContentType($get)) === [])
                ->searchable()
                // Rebase the path under the chosen parent right in the form, so the
                // URL preview reflects the hierarchy before saving (the saving hook
                // enforces the same rule server-side).
                ->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set) use ($tenant): void {
                    $rebased = static::rebasePathUnderParent(
                        $tenant,
                        $state !== null ? (int) $state : null,
                        (string) ($get('path') ?? ''),
                        (string) ($get('title') ?? ''),
                    );

                    if ($rebased !== null) {
                        $set('path', $rebased);
                    }
                })
                ->helperText('Optional. Der Pfad ordnet sich der übergeordneten Seite unter.'),
            // Path is now edited via TitleWithSlugInput (combined with title)
        ];
    }

    /**
     * The path a record should get under the given parent: parent path + own last
     * segment. Returns null when nothing sensible can be derived (no title/path yet).
     */
    protected static function rebasePathUnderParent(?Tenant $tenant, ?int $parentId, string $currentPath, string $title): ?string
    {
        $segment = filled($currentPath)
            ? Str::afterLast(trim($currentPath, '/'), '/')
            : Str::slug($title);

        if (blank($segment)) {
            return null;
        }

        if ($parentId === null) {
            return '/'.$segment;
        }

        $parentPath = Cms::contentModel()::query()
            ->when($tenant, fn (EloquentBuilder $query) => $query->whereBelongsTo($tenant))
            ->find($parentId)
            ?->resolvedPath();

        if (blank($parentPath)) {
            return '/'.$segment;
        }

        return rtrim($parentPath, '/').'/'.$segment;
    }

    protected static function templateField(): TextInput
    {
        return TextInput::make('template')
            ->maxLength(255)
            ->helperText('Optionales Template-Override. Leer = Standard aus dem Content-Type.');
    }

    protected static function layoutPresetField(?Tenant $tenant): Select
    {
        return LayoutPreset::selectField('content', $tenant)
            ->helperText('Steuert die Breite und das Layout der Seite.');
    }

    protected static function teaserToggleField(): Toggle
    {
        return Toggle::make('payload.has_teaser')
            ->label('Teaser-Modus')
            ->helperText('Zeigt einen Teaser auf dem Onepager und die Seite als eigene Unterseite.')
            ->columnSpanFull()
            ->live();
    }

    protected static function builderField(?Tenant $tenant): Builder
    {
        return static::makeBuilder('blocks', $tenant);
    }

    protected static function teaserBuilderField(?Tenant $tenant): Builder
    {
        return static::makeBuilder('payload.teaser_blocks', $tenant);
    }

    /**
     * Shared block-builder configuration (icons, per-item options incl. the
     * background-image upload for sections, block set). Used for both the main
     * content blocks and the teaser blocks, differing only in the state path.
     */
    protected static function makeBuilder(string $statePath, ?Tenant $tenant): Builder
    {
        return BlockBuilder::make($statePath, $tenant, static::getBuilderBlocks($tenant), previews: false)
            ->columnSpanFull();
    }

    /**
     * SEO override fields, provided by the shared {@see SeoFields} kit so future
     * improvements land in one place for every project. Wiring/placement stays
     * a per-resource choice (compose, reorder, extend or replace the kit).
     *
     * @return array<int, Component>
     */
    protected static function metaFields(): array
    {
        return SeoFields::make()->toArray();
    }

    // -------------------------------------------------------------------------
    //  Hook Methods (override in subclasses)
    // -------------------------------------------------------------------------

    /**
     * @return array{urlPath?: string|\Closure|null, urlVisitLinkVisible?: bool|\Closure, urlVisitLinkRoute?: \Closure|null}
     */
    protected static function titleSlugConfig(): array
    {
        return [];
    }

    /**
     * Extension hook: components rendered between the title row and the tabs.
     * Empty by default — the content_type field lives in the title row
     * ({@see titleRowComponents()}).
     *
     * @return array<int, Component>
     */
    protected static function beforeTabs(?Tenant $tenant): array
    {
        return [];
    }

    /**
     * Sidebar fields shown next to the builder (or in Einstellungen tab when no builder).
     *
     * @return array<int, Component>
     */
    protected static function sidebarFields(?Tenant $tenant): array
    {
        return static::structureFields($tenant);
    }

    /**
     * Full-width content sections rendered in the "Inhalt" tab — for a builder type
     * below the block builder, for a builder-less type as the tab's main column.
     * Default: the blueprint's structured payload fields wrapped in a section (empty
     * when the blueprint defines none).
     *
     * The Meta section is NOT part of this anymore — it lives in the sidebar via
     * {@see contentSidebar()}. Override to add custom content sections; do not append
     * a Meta section (it would render twice).
     *
     * @return array<int, Component>
     */
    protected static function detailSections(?Tenant $tenant): array
    {
        $payloadComponents = static::resolveFormBlueprint()?->payloadFormComponents() ?? [];

        if ($payloadComponents === []) {
            return [];
        }

        return [
            Section::make('Details')
                ->columns(2)
                ->schema($payloadComponents),
        ];
    }

    /**
     * The collapsed "Meta" section (SEO overrides via the {@see SeoFields} kit). One
     * definition so every resource's Details tab presents the same section — pass a
     * custom schema only to deviate.
     *
     * @param  array<int, Component>|null  $schema
     */
    protected static function metaSection(?array $schema = null): Section
    {
        return Section::make('Meta')
            ->description('SEO-Overrides und weitere Metadaten.')
            ->collapsed()
            ->collapsible()
            ->schema($schema ?? static::metaFields());
    }

    /**
     * Opt-in raw payload editor: a generic KeyValue field for arbitrary `payload.*`
     * keys, rendered collapsed at the very end of the form. Only shown for blueprints
     * that set showsPayloadEditor() (off by default), and only meaningful for types
     * without structured payloadFormComponents() — it round-trips the whole payload.
     */
    protected static function rawPayloadSection(): Section
    {
        return Section::make('Payload')
            ->description('Strukturierte Zusatzdaten für spezielle Templates oder Frontend-Logik.')
            ->collapsed()
            ->collapsible()
            ->columnSpanFull()
            ->schema([
                KeyValue::make('payload')
                    ->hiddenLabel()
                    ->columnSpanFull(),
            ]);
    }

    // -------------------------------------------------------------------------
    //  Table
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        $tenant = app(CurrentTenant::class)->get();

        // Non-routable types (e.g. a taxonomy term or fragment) have no path — show their
        // tenant-unique slug instead, mirroring the slug-only form input.
        $routable = static::resolveFormBlueprint()?->isRoutable() ?? true;

        return $table
            ->recordTitleAttribute('title')
            ->reorderable('sort')
            ->defaultSort('sort')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            // Optional hierarchy view: group rows under their parent page.
            ->groups([
                Group::make('parent.title')
                    ->label('Übergeordnete Seite')
                    ->getTitleFromRecordUsing(fn (Model $record): string => $record->parent?->title ?? 'Oberste Ebene')
                    ->collapsible(),
            ])
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    // Hierarchy at a glance: indent nested pages by their path depth.
                    ->formatStateUsing(function ($state, Model $record): string {
                        $depth = filled($record->path)
                            ? substr_count(trim((string) $record->path, '/'), '/')
                            : 0;

                        return str_repeat('↳ ', min($depth, 4)).$state;
                    }),
                TextColumn::make('content_type')
                    ->label('Typ')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => app(ContentBlueprintRegistry::class)
                        ->find((string) $state, $tenant?->site_key)?->label() ?? (string) $state)
                    ->visible(count(static::getContentTypes()) !== 1),
                TextColumn::make('path')
                    ->searchable()
                    ->visible($routable),
                TextColumn::make('slug')
                    ->searchable()
                    ->visible(! $routable),
                TextColumn::make('visibility')
                    ->label('Sichtbarkeit')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ContentVisibility::options()[$state instanceof ContentVisibility ? $state->value : (string) $state] ?? (string) $state),
                TextColumn::make('resolved_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ContentStatus::options()[(string) $state] ?? (string) $state)
                    ->color(fn ($state): string => match ((string) $state) {
                        'published' => 'success',
                        'scheduled' => 'info',
                        'expired' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters(static::tableFilters())
            ->recordActions([
                ActionGroup::make([
                    Action::make('open')
                        ->label('Öffnen')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Content $record): ?string => filled($record->resolvedPath())
                            ? route('content.show', ['path' => ltrim($record->resolvedPath(), '/')])
                            : null)
                        ->openUrlInNewTab()
                        ->visible(fn (Content $record): bool => filled($record->resolvedPath())),
                    EditAction::make(),
                    ReplicateAction::make()
                        ->label('Duplizieren')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->schema([static::getDuplicateInput($tenant)])
                        ->mutateRecordDataUsing(function (array $data): array {
                            // Prefill the modal: append "(Kopie)" to the title and derive a
                            // fresh, collision-free path/slug from it (the user can override).
                            $blueprint = static::resolveFormBlueprint();
                            $title = trim(($data['title'] ?? '').' (Kopie)');

                            $data['title'] = $title;

                            if ($blueprint?->isRoutable() ?? true) {
                                $data['path'] = (static::pathSlugifier($blueprint?->urlPathPrefix()))($title);
                                $data['slug'] = null;
                            } else {
                                $data['path'] = null;
                                $data['slug'] = Str::slug($title);
                            }

                            return $data;
                        })
                        ->beforeReplicaSaved(function (Model $replica, array $data): void {
                            // Apply the (possibly edited) title + path/slug from the modal and
                            // start the copy as a draft. A blank value is regenerated by
                            // GeneratesPathAndSlug on save.
                            $replica->title = $data['title'] ?? $replica->title;
                            $replica->path = $data['path'] ?? null;
                            $replica->slug = $data['slug'] ?? null;
                            $replica->publish_from = null;
                        })
                        ->successRedirectUrl(fn (Model $replica): string => static::getUrl('edit', ['record' => $replica]))
                        ->successNotificationTitle('Inhalt dupliziert'),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Table filters for the listing. Override in subclasses to add type-specific
     * filters (e.g. a category filter on the machine list).
     *
     * @return array<int, mixed>
     */
    protected static function tableFilters(): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    //  Navigation & Access
    // -------------------------------------------------------------------------

    public static function getNavigationLabel(): string
    {
        $blueprint = static::resolveFormBlueprint();

        return $blueprint?->navigationLabel() ?? $blueprint?->pluralLabel() ?? parent::getNavigationLabel();
    }

    public static function getModelLabel(): string
    {
        $blueprint = static::resolveFormBlueprint();

        return $blueprint?->label() ?? parent::getModelLabel();
    }

    public static function getPluralModelLabel(): string
    {
        $blueprint = static::resolveFormBlueprint();

        return $blueprint?->pluralLabel() ?? static::getNavigationLabel();
    }

    public static function canAccess(): bool
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant === null) {
            return false;
        }

        $siteKeys = static::resolvedSiteKeys();

        if ($siteKeys !== [] && ! in_array($tenant->site_key, $siteKeys, true)) {
            return false;
        }

        return parent::canAccess();
    }

    /**
     * Resolves site keys from explicit property or namespace convention.
     *
     * Convention: A Resource at `App\Sites\{ExtensionDir}\Resources\*` derives
     * its site key from the SiteExtension in the parent namespace.
     *
     * @return array<int, string>
     */
    protected static function resolvedSiteKeys(): array
    {
        if (static::$siteKeys !== []) {
            return static::$siteKeys;
        }

        $class = static::class;
        $sitesNamespace = Cms::sitesNamespace().'\\';

        if (! str_starts_with($class, $sitesNamespace)) {
            return [];
        }

        $afterSites = substr($class, strlen($sitesNamespace));
        $extensionDir = strstr($afterSites, '\\', before_needle: true);

        if ($extensionDir === false) {
            return [];
        }

        $registry = app(SiteExtensionRegistry::class);

        foreach ($registry->all() as $extension) {
            if ($extension::class === $sitesNamespace.$extensionDir.'\\SiteExtension') {
                return [$extension->siteKey()];
            }
        }

        return [];
    }

    /**
     * Auto-derives the URL slug from the blueprint's plural label when not explicitly set.
     */
    public static function getSlug(?Panel $panel = null): string
    {
        if (static::$slug !== null) {
            return static::$slug;
        }

        $blueprint = static::resolveFormBlueprint();

        if ($blueprint?->pluralLabel() !== null) {
            return Str::slug($blueprint->pluralLabel());
        }

        return parent::getSlug($panel);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    // -------------------------------------------------------------------------
    //  Eloquent Query
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): EloquentBuilder
    {
        $tenant = app(CurrentTenant::class)->get();
        $query = parent::getEloquentQuery();

        if ($tenant === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereBelongsTo($tenant);

        if (static::getContentTypes() !== []) {
            $query->whereIn('content_type', static::getContentTypes());
        }

        if (static::supportsParentScopedListing()) {
            $requestedParentId = static::resolveRequestedParentId($tenant);

            if ($requestedParentId !== null) {
                $query->where('parent_id', $requestedParentId);
            }
        }

        return $query;
    }

    public static function getRequestedParentId(): ?int
    {
        return static::resolveRequestedParentId(app(CurrentTenant::class)->get());
    }

    public static function getRequestedParentRecord(): ?Content
    {
        $tenant = app(CurrentTenant::class)->get();
        $requestedParentId = static::resolveRequestedParentId($tenant);

        if ($tenant === null || $requestedParentId === null) {
            return null;
        }

        return Cms::contentModel()::query()
            ->whereBelongsTo($tenant)
            ->find($requestedParentId);
    }

    // -------------------------------------------------------------------------
    //  Content Type Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    public static function getContentTypes(): array
    {
        if (static::$contentTypes !== []) {
            return static::$contentTypes;
        }

        $blueprint = static::resolveSiblingBlueprint();

        return $blueprint !== null ? [$blueprint->key()] : [];
    }

    /**
     * @return array<string, string>
     */
    protected static function getContentTypeOptions(): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $options = app(ContentBlueprintRegistry::class)->options($tenant?->site_key);

        if (static::getContentTypes() === []) {
            return $options;
        }

        return array_intersect_key($options, array_flip(static::getContentTypes()));
    }

    /**
     * The title/slug input with the content-type field: the type is a hidden field
     * pinned to the default type unless the type choice is enabled for the tenant's
     * site ({@see contentTypeSelectEnabled()}) — then the Seiten-Typ select sits
     * BESIDE the title input (right column).
     *
     * @return array<int, Component>
     */
    protected static function titleRowComponents(?Tenant $tenant): array
    {
        $typeComponents = static::getContentTypeFormComponents(static::getContentTypeOptions());
        $title = static::buildTitleWithSlugInput($tenant);

        $select = collect($typeComponents)->first(fn ($component): bool => $component instanceof Select);

        if ($select === null) {
            return [...$typeComponents, $title];
        }

        $hiddenComponents = collect($typeComponents)->reject(fn ($component): bool => $component === $select)->all();

        return [
            ...$hiddenComponents,
            Grid::make(['default' => 1, 'lg' => 3])
                ->schema([
                    $title->columnSpan(['default' => 1, 'lg' => 2]),
                    $select->columnSpan(['default' => 1, 'lg' => 1]),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * The content_type backing field. A hidden field pinned to the default type
     * unless the tenant's site offers MORE than one selectable type. What is
     * selectable is declared on the BLUEPRINT ({@see ConfiguredContentBlueprint::$offeredInTypeSelect}):
     * routable + offered — non-routable types (fixtures, embedded records) and
     * types a site keeps out of the picker (e.g. default.section on pages-only
     * sites) never appear here.
     *
     * @param  array<string, string>  $contentTypeOptions
     * @return array<int, Component>
     */
    protected static function getContentTypeFormComponents(array $contentTypeOptions): array
    {
        $selectable = static::selectableContentTypeOptions($contentTypeOptions);

        if (count($selectable) <= 1) {
            return [
                Hidden::make('content_type')
                    ->default(static::defaultContentType())
                    ->required(),
            ];
        }

        return [
            Select::make('content_type')
                ->label('Seiten-Typ')
                ->required()
                ->default(static::defaultContentType())
                ->options($selectable)
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state) use ($selectable): void {
                    if (! array_key_exists((string) $state, $selectable)) {
                        return;
                    }

                    $set('parent_id', static::getDefaultParentId(app(CurrentTenant::class)->get(), $state));
                    $set('visibility', static::getDefaultVisibility($state));
                }),
        ];
    }

    /**
     * The type new records default to: `default.page` when managed, else the
     * resource's first type. Also the pinned value while the select is hidden.
     */
    protected static function defaultContentType(): ?string
    {
        $types = static::getContentTypes();

        return in_array('default.page', $types, true) ? 'default.page' : ($types[0] ?? null);
    }

    /**
     * @param  array<string, string>  $contentTypeOptions
     * @return array<string, string>
     */
    protected static function selectableContentTypeOptions(array $contentTypeOptions): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $registry = app(ContentBlueprintRegistry::class);

        return array_filter(
            $contentTypeOptions,
            function (string $key) use ($registry, $tenant): bool {
                $blueprint = $registry->find($key, $tenant?->site_key);

                return ($blueprint?->isRoutable() ?? true)
                    && ($blueprint?->offeredInTypeSelect() ?? true);
            },
            ARRAY_FILTER_USE_KEY,
        );
    }

    protected static function resolveSelectedContentType(Get $get): ?string
    {
        return $get('content_type') ?: static::getContentTypes()[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    protected static function getAllowedParentTypes(?string $contentType): array
    {
        return static::selectedBlueprint($contentType)?->allowedParentTypes() ?? [];
    }

    /**
     * @return array<int|string, string>
     */
    protected static function getParentOptions(?Tenant $tenant, ?string $contentType, ?Model $record = null): array
    {
        $allowedParentTypes = static::getAllowedParentTypes($contentType);

        if ($tenant === null || $allowedParentTypes === []) {
            return [];
        }

        $options = Cms::contentModel()::query()
            ->whereBelongsTo($tenant)
            ->whereIn('content_type', $allowedParentTypes)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();

        // A record can never be its own ancestor: exclude itself and every
        // descendant, or reparenting could create a cycle.
        if ($record?->getKey() !== null) {
            $excluded = static::selfAndDescendantIds($tenant, (int) $record->getKey());
            $options = array_diff_key($options, array_flip($excluded));
        }

        return $options;
    }

    /**
     * The record id plus all ids below it in the parent_id tree (computed from a
     * single id→parent_id map — content sets per tenant are small).
     *
     * @return list<int>
     */
    protected static function selfAndDescendantIds(Tenant $tenant, int $recordId): array
    {
        $parents = Cms::contentModel()::query()
            ->whereBelongsTo($tenant)
            ->whereNotNull('parent_id')
            ->pluck('parent_id', 'id')
            ->all();

        $childrenByParent = [];

        foreach ($parents as $id => $parentId) {
            $childrenByParent[(int) $parentId][] = (int) $id;
        }

        $collected = [$recordId];
        $queue = [$recordId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($childrenByParent[$current] ?? [] as $childId) {
                if (! in_array($childId, $collected, true)) {
                    $collected[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $collected;
    }

    protected static function getDefaultParentId(?Tenant $tenant, ?string $contentType): ?int
    {
        $requestedParentId = static::resolveRequestedParentId($tenant, $contentType);

        if ($requestedParentId !== null) {
            return $requestedParentId;
        }

        $parentOptions = static::getParentOptions($tenant, $contentType);

        return count($parentOptions) === 1 ? (int) array_key_first($parentOptions) : null;
    }

    protected static function getDefaultVisibility(?string $contentType): string
    {
        return static::selectedBlueprint($contentType)?->defaultVisibility()->value ?? ContentVisibility::Public->value;
    }

    protected static function selectedBlueprint(?string $contentType): ?ContentBlueprint
    {
        if ($contentType === null) {
            return null;
        }

        $tenant = app(CurrentTenant::class)->get();

        return app(ContentBlueprintRegistry::class)->find($contentType, $tenant?->site_key);
    }

    /**
     * Resolves the blueprint for form rendering.
     *
     * Tries the sibling Blueprint class first (per-type folder convention),
     * then falls back to registry lookup via content type key.
     */
    protected static function resolveFormBlueprint(): ?ContentBlueprint
    {
        return static::resolveSiblingBlueprint()
            ?? static::selectedBlueprint(static::$contentTypes[0] ?? null);
    }

    /**
     * Looks for a Blueprint class in the same namespace as this Resource.
     *
     * Convention: `App\Sites\Blog\Article\Resource`
     * → resolves `App\Sites\Blog\Article\Blueprint`
     */
    protected static function resolveSiblingBlueprint(): ?ContentBlueprint
    {
        $namespace = substr(static::class, 0, strrpos(static::class, '\\'));
        $blueprintClass = $namespace.'\\Blueprint';

        if (! class_exists($blueprintClass) || ! is_subclass_of($blueprintClass, ContentBlueprint::class)) {
            return null;
        }

        return app($blueprintClass);
    }

    protected static function supportsParentScopedListing(): bool
    {
        return static::$supportsParentScopedListing;
    }

    // -------------------------------------------------------------------------
    //  Title / Path
    // -------------------------------------------------------------------------

    protected static function buildTitleWithSlugInput(?Tenant $tenant): FusedGroup
    {
        $config = static::titleSlugConfig();
        $blueprint = static::resolveFormBlueprint();

        // Non-routable types have no path/URL — edit a plain, tenant-unique slug instead
        // of the "Pfad" field (paired with the GeneratesPathAndSlug saving logic, which
        // keeps path null and the slug populated).
        if ($blueprint !== null && ! $blueprint->isRoutable()) {
            return static::getSlugOnlyInput($tenant);
        }

        $pathPrefix = $config['urlPath'] ?? $blueprint?->urlPathPrefix();

        // Slug stores the full path including the leading "/" (e.g. "/category/item"),
        // which matches PathGenerator::normalize() and keeps the preview consistent
        // before and after save without form-state mutation.
        $urlPath = '';

        $urlVisitLinkVisible = $config['urlVisitLinkVisible']
            ?? ($blueprint !== null
                ? $blueprint->isRoutable()
                : fn (?Content $record): bool => filled($record?->resolvedPath()));

        $urlVisitLinkRoute = $config['urlVisitLinkRoute']
            ?? fn (?Content $record): ?string => filled($record?->resolvedPath())
                ? route('content.show', ['path' => ltrim($record->resolvedPath(), '/')])
                : null;

        return static::getTitleWithSlugInput(
            tenant: $tenant,
            urlPath: $urlPath,
            pathPrefix: $pathPrefix,
            urlVisitLinkVisible: $urlVisitLinkVisible,
            urlVisitLinkRoute: $urlVisitLinkRoute,
        );
    }

    protected static function getTitleWithSlugInput(
        ?Tenant $tenant,
        string|\Closure|null $urlPath = '/',
        ?string $pathPrefix = null,
        bool|\Closure $urlVisitLinkVisible = false,
        ?\Closure $urlVisitLinkRoute = null,
    ): FusedGroup {
        return TitleWithSlugInput::make(
            fieldTitle: 'title',
            fieldSlug: 'path',
            urlPath: $urlPath,
            urlHost: static fn (): string => request()->getSchemeAndHttpHost(),
            urlHostVisible: false,
            urlVisitLinkVisible: $urlVisitLinkVisible,
            urlVisitLinkRoute: $urlVisitLinkRoute,
            urlVisitLinkLabel: 'Seite öffnen',
            titleLabel: 'Titel',
            slugLabel: 'Pfad',
            slugRuleUniqueParameters: static::getPathRuleUniqueParameters($tenant),
            slugRuleRegex: '/^[a-z0-9\-\_\/]*$/',
            slugSlugifier: static::pathSlugifier($pathPrefix),
        )->columnSpanFull();
    }

    /**
     * Slugifier for the path field: strips an existing prefix so repeated edits don't
     * double it (e.g. "/projekte/kanalbau" → "kanalbau" → "/projekte/kanalbau") and
     * re-applies the blueprint's urlPathPrefix.
     */
    protected static function pathSlugifier(?string $pathPrefix): \Closure
    {
        $normalizedPrefix = $pathPrefix ? trim($pathPrefix, '/') : null;

        return static function (string $text) use ($normalizedPrefix): string {
            $text = ltrim($text, '/');

            if ($normalizedPrefix && str_starts_with($text, $normalizedPrefix.'/')) {
                $text = substr($text, strlen($normalizedPrefix) + 1);
            }

            $slug = Str::slug($text);

            return $normalizedPrefix ? '/'.$normalizedPrefix.'/'.$slug : '/'.$slug;
        };
    }

    /**
     * Title + a plain, tenant-unique slug (no path/URL) — for non-routable content types.
     */
    protected static function getSlugOnlyInput(?Tenant $tenant): FusedGroup
    {
        return TitleWithSlugInput::make(
            fieldTitle: 'title',
            fieldSlug: 'slug',
            urlPath: '',
            urlHostVisible: false,
            urlVisitLinkVisible: false,
            titleLabel: 'Titel',
            slugLabel: 'Slug',
            slugRuleUniqueParameters: static::getPathRuleUniqueParameters($tenant),
            slugRuleRegex: '/^[a-z0-9\-\_]*$/',
            slugSlugifier: fn (string $text): string => Str::slug($text),
        )->columnSpanFull();
    }

    /**
     * Title + path (or slug, for non-routable types) input for the Duplizieren modal.
     * Unlike the form input it shows no URL host/visit link, and its uniqueness rule
     * does NOT ignore the source record — the copy must get its own path/slug.
     */
    protected static function getDuplicateInput(?Tenant $tenant): FusedGroup
    {
        $blueprint = static::resolveFormBlueprint();
        $routable = $blueprint?->isRoutable() ?? true;

        return TitleWithSlugInput::make(
            fieldTitle: 'title',
            fieldSlug: $routable ? 'path' : 'slug',
            urlPath: '',
            urlHostVisible: false,
            urlVisitLinkVisible: false,
            titleLabel: 'Titel',
            slugLabel: $routable ? 'Pfad' : 'Slug',
            slugRuleUniqueParameters: static::getPathRuleUniqueParameters($tenant, ignoreCurrentRecord: false),
            slugRuleRegex: $routable ? '/^[a-z0-9\-\_\/]*$/' : '/^[a-z0-9\-\_]*$/',
            slugSlugifier: $routable
                ? static::pathSlugifier($blueprint?->urlPathPrefix())
                : fn (string $text): string => Str::slug($text),
        )->columnSpanFull();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getPathRuleUniqueParameters(?Tenant $tenant, bool $ignoreCurrentRecord = true): array
    {
        $parameters = [
            'modifyRuleUsing' => function (Unique $rule) use ($tenant): Unique {
                $rule->where('tenant_id', $tenant?->getKey());

                return $rule;
            },
        ];

        if ($ignoreCurrentRecord) {
            $parameters['ignorable'] = fn (?Model $record): ?Model => $record;
        }

        return $parameters;
    }

    // -------------------------------------------------------------------------
    //  Parent Scoping
    // -------------------------------------------------------------------------

    protected static function resolveRequestedParentId(?Tenant $tenant, ?string $contentType = null): ?int
    {
        $requestedParentId = request()->integer('parent') ?: request()->integer('parent_id');

        if ($tenant === null || $requestedParentId < 1) {
            return null;
        }

        $parent = Cms::contentModel()::query()
            ->whereBelongsTo($tenant)
            ->find($requestedParentId);

        if (! $parent instanceof Content) {
            return null;
        }

        $allowedParentTypes = static::getAllowedParentTypes($contentType);

        if (
            $contentType !== null &&
            $allowedParentTypes !== [] &&
            ! in_array($parent->content_type, $allowedParentTypes, true)
        ) {
            return null;
        }

        return (int) $parent->getKey();
    }

    // -------------------------------------------------------------------------
    //  Builder Blocks
    // -------------------------------------------------------------------------

    /**
     * Top-level blocks offered by the page builder. Defaults to `section` only; a site
     * may allow additional top-level (e.g. full-bleed) blocks via
     * `Cms::allowRootBlocks('{site_key}', […])`.
     *
     * @return array<int, Block>
     */
    protected static function getBuilderBlocks(?Tenant $tenant): array
    {
        return app(BuilderBlockRegistry::class)->only(Cms::rootBlockAllowlist($tenant?->site_key), $tenant);
    }
}
