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
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Component;
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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Livewire\Component as LivewireComponent;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\CmsServiceProvider;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Fields\PublishingFields;
use Mmoollllee\Cms\Fields\SeoFields;
use Mmoollllee\Cms\Filament\Forms\BlockBuilder;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;
use Mmoollllee\Cms\Support\Content\Blocks\section\SectionBlock;
use Mmoollllee\Cms\Support\Content\ContentTree;
use Mmoollllee\Cms\Support\Content\FrontendUrl;
use Mmoollllee\Cms\Support\Content\PathConflicts;
use Mmoollllee\Cms\Support\Preview\Drafts;
use Mmoollllee\Cms\Support\Routing\ContentRenameRedirects;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;
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
            ...static::titleRowComponents($tenant, static::currentContentType($schema)),
            ...static::beforeTabs($tenant),
            Tabs::make(static::getModelLabel())
                ->contained(false)
                ->tabs($tabs)
                ->columnSpanFull(),
            // Opt-in raw payload editor, collapsed at the very end of the page.
            // Only add it to the schema when this form actually uses it: the KeyValue
            // binds to the `payload` state path and hydrates even while hidden, so a
            // plain visible(false) still rewrites structured `payload.*` field values
            // into key/value pairs on fill and blanks them. Keeping it out of the tree
            // entirely (rather than hidden) is what preserves those fields.
            ...(static::formIncludesPayloadEditor() ? [
                static::rawPayloadSection()
                    ->visible(static::formShowsPayloadEditor()),
            ] : []),
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
     * Whether the form's content type is routable (has its own URL), as a Get-aware
     * closure. Same static-vs-reactive split as {@see formSupportsTeasers()}.
     * Gates the Meta (SEO) section: a non-routable type never renders as its own
     * page, so SEO overrides would be dead settings.
     */
    protected static function formIsRoutable(): Closure
    {
        $isRoutable = static::resolveFormBlueprint()?->isRoutable() ?? true;

        return fn (Get $get): bool => $isRoutable;
    }

    /**
     * Whether the raw payload editor is part of the form tree at all. Single-type
     * resources include it only when their blueprint opts in ({@see showsPayloadEditor()}),
     * so it never collides with structured `payload.*` fields. The multi-type catch-all
     * keeps it in the tree and toggles it reactively ({@see formShowsPayloadEditor()}),
     * overriding this to true.
     */
    protected static function formIncludesPayloadEditor(): bool
    {
        return static::resolveFormBlueprint()?->showsPayloadEditor() ?? false;
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
                    // The description IS the live effect sentence ("Für Besucher
                    // sichtbar — wird am … automatisch ausgeblendet."): it states
                    // what the current setting does instead of restating the field
                    // labels below it.
                    ->description(PublishingFields::sectionDescription())
                    // Toggle + publish_from + publish_until share one row.
                    ->columns(3)
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
     * types (e.g. a listing/category page) there is no sidebar: page header, detail
     * sections, structure fields and Meta stack full width — a 1/3 column holding
     * little more than the collapsed Meta section would leave the page half empty
     * next to it. Shared by the dedicated resources and the catch-all so the content
     * area looks and behaves identically.
     */
    protected static function contentTab(?Tenant $tenant): Tab
    {
        $pageHeader = static::pageHeaderSection($tenant);
        $detailSections = static::detailSections($tenant);

        if (! static::contentTabHasBuilder()) {
            return Tab::make('Inhalt')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    ...($pageHeader !== null ? [$pageHeader] : []),
                    ...$detailSections,
                    ...static::stackedStructureFields($tenant),
                    static::contentTabMetaSection(),
                ]);
        }

        $sidebar = static::contentSidebar($tenant);

        // The reactive span lets the main column reclaim the full width when nothing
        // in the sidebar is visible — e.g. a non-routable type (Meta section hidden)
        // with no parent select. getChildComponents() returns only the currently
        // visible children.
        $sidebarHasVisibleContent = static fn (): bool => $sidebar->getChildComponents() !== [];
        $mainSpan = static fn (): array => $sidebarHasVisibleContent()
            ? ['default' => 1, 'xl' => 2]
            : ['default' => 1, 'xl' => 3];

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

    /**
     * The sidebar's structure fields ({@see sidebarFields()}) for the builder-less
     * layout: wrapped in a two-column grid so a lone select does not stretch across
     * the whole page. Empty when the resource declares no sidebar fields, so the
     * Meta section follows the detail sections directly.
     *
     * Hidden-only sets (e.g. a derived parent_id) are returned bare: a grid around
     * them renders an empty column pair — and its schema gap — above the Meta section.
     *
     * @return array<int, Component>
     */
    protected static function stackedStructureFields(?Tenant $tenant): array
    {
        $fields = static::sidebarFields($tenant);

        $laidOutFields = array_filter($fields, static fn (Component $field): bool => ! $field instanceof Hidden);

        if ($laidOutFields === []) {
            return $fields;
        }

        return [
            Grid::make(['default' => 1, 'md' => 2])->schema($fields),
        ];
    }

    /**
     * The "Meta" (SEO) section as the "Inhalt" tab places it — in the sidebar beside
     * the builder, stacked below the structure fields without one. Non-routable types
     * have no page of their own to optimize, so it hides for them; keeping that rule
     * here means both layouts always agree on it.
     */
    protected static function contentTabMetaSection(): Section
    {
        return static::metaSection()
            ->visible(static::formIsRoutable());
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
     * The "Inhalt"-tab sidebar next to the block builder: the resource's structure/
     * attribute fields (as returned by {@see sidebarFields()} — bare fields or grouped
     * sections, the resource's choice) above the collapsed "Meta" (SEO) section.
     * Builder-less types stack the same two full width instead ({@see contentTab()}).
     * Either way the Meta section hides for non-routable types ({@see formIsRoutable()}),
     * which have no page of their own to optimize.
     */
    protected static function contentSidebar(?Tenant $tenant): Grid
    {
        return Grid::make(1)
            ->columnSpan(['default' => 1, 'xl' => 1])
            ->schema([
                ...static::sidebarFields($tenant),
                static::contentTabMetaSection(),
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
     * Publishing fields: the "Veröffentlicht" toggle with its publish_from /
     * publish_until window, the derived status badge, and the persisted (but
     * UI-less) visibility value.
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
                ->live()
                ->afterStateUpdated(static::rebasePathOnParentChange($tenant))
                ->helperText('Optional. Der Pfad ordnet sich der übergeordneten Seite unter.'),
            // Path is now edited via TitleWithSlugInput (combined with title)
        ];
    }

    /**
     * `afterStateUpdated` for a parent Select: shows the editor the URL the record will
     * actually have under the chosen parent, before saving.
     *
     * Exposed so a resource that supplies its own parent Select ({@see structureFields()}
     * being replaced wholesale) can keep the behaviour instead of leaving the editor to
     * discover the move from a validation error.
     *
     * The preview is ASKED of the generator ({@see pathThisFormWouldStore()}), never
     * composed here. A preview composed by hand is not a preview at all: the generator
     * keeps a filled path for the branches it does not own, so a hand-composed value does
     * not predict the save — it becomes it, wrong prefix and all, and every later save
     * reads it back as the typed value. Prefixes, nesting and non-routable types therefore
     * need no special case here; the one authority answers for all of them.
     */
    protected static function rebasePathOnParentChange(?Tenant $tenant): Closure
    {
        return static function ($state, Get $get, Set $set, ?Model $record) use ($tenant): void {
            $typed = (string) ($get('path') ?? '');

            $segment = filled($typed)
                ? app(PathNormalizer::class)->lastSegment($typed)
                : Str::slug((string) ($get('title') ?? ''));

            if (blank($segment)) {
                return;
            }

            // Clearing the parent is the one case where the form AUTHORS rather than
            // previews: the editor is moving the record to the root, so offer the bare
            // segment and let the generator confirm where that lands.
            $candidate = filled($state) ? $typed : '/'.$segment;

            $stored = static::pathThisFormWouldStore($get, $record, $tenant, $candidate);

            if (filled($stored)) {
                $set('path', $stored);
            }
        };
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
     *
     * Previews are ON, but that is decided per BLOCK, not per builder: the
     * builder override renders a card only for a block that declares a
     * `->preview()` view and leaves every other one as an open form
     * ({@see SectionBlock},
     * which is the only top-level block most sites offer, has none). So this
     * changes nothing for a section-only page builder and gives blocks that DO
     * have a preview — the note, and whatever a site allows at the top level —
     * the click-to-edit card they were written for.
     */
    protected static function makeBuilder(string $statePath, ?Tenant $tenant): Builder
    {
        return BlockBuilder::make($statePath, $tenant, static::getBuilderBlocks($tenant))
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
     * @return array{urlPath?: string|Closure|null, urlVisitLinkVisible?: bool|Closure, urlVisitLinkRoute?: Closure|null}
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
     * Structure fields for the "Inhalt" tab: shown in the sidebar next to the block
     * builder, or stacked full width above the Meta section for builder-less types.
     *
     * @return array<int, Component>
     */
    protected static function sidebarFields(?Tenant $tenant): array
    {
        return static::structureFields($tenant);
    }

    /**
     * Full-width content sections rendered in the "Inhalt" tab — for a builder type
     * below the block builder, for a builder-less type below the page header.
     * Default: the blueprint's structured payload fields wrapped in a section (empty
     * when the blueprint defines none).
     *
     * The Meta section is NOT part of this: {@see contentTab()} places it centrally,
     * either in the sidebar or (builder-less) right below these sections. Override to
     * add custom content sections; do not append a Meta section (it would render twice).
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
     *
     * The editor works on a COPY (`raw_payload`), never on the `payload` state path
     * itself: Filament drops a hidden component's state path from the dehydrated
     * state ENTIRELY, so a hidden editor bound to `payload` would erase every
     * sibling structured field on save (payload.hero.*, payload.has_teaser, teaser
     * blocks — the catch-all keeps the editor in the tree and only toggles
     * visibility). The pages fold the copy back via {@see mergeRawPayload()},
     * which only sees the key when the editor was visible and dehydrated.
     */
    protected static function rawPayloadSection(): Section
    {
        return Section::make('Payload')
            ->description('Strukturierte Zusatzdaten für spezielle Templates oder Frontend-Logik.')
            ->collapsed()
            ->collapsible()
            ->columnSpanFull()
            ->schema([
                // The copy is FILLED by the edit page (ContentEditPage seeds
                // `raw_payload` from the record's/draft's payload in its fill
                // mutation) — hydrating from the sibling `payload` state here
                // would pick up field-hydration artifacts (empty hero rows,
                // toggles) instead of the stored payload.
                KeyValue::make('raw_payload')
                    ->hiddenLabel()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Fold the raw payload editor's copy back into `payload`. The `raw_payload`
     * key exists in the dehydrated form state only while the editor is visible;
     * structured `payload.*` fields win on key collisions.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mergeRawPayload(array $data): array
    {
        if (! array_key_exists('raw_payload', $data)) {
            return $data;
        }

        $rawPayload = is_array($data['raw_payload']) ? $data['raw_payload'] : [];

        $data['payload'] = ($data['payload'] ?? []) + $rawPayload;

        unset($data['raw_payload']);

        return $data;
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
            // The "↳" indent walks the parent chain per row — keep it query-free.
            ->modifyQueryUsing(fn (EloquentBuilder $query) => $query->with('parent.parent.parent.parent'))
            ->columns([
                TextColumn::make('title')
                    ->label('Titel')
                    ->searchable()
                    // Hierarchy at a glance: indent a row under ancestors this table
                    // actually lists. Flat single-type or parent-scoped listings
                    // (machines, categories) show no indent — the parent row the
                    // arrow would point at is not part of the table.
                    ->formatStateUsing(fn ($state, Model $record): string => str_repeat('↳ ', static::listedAncestorDepth($record)).$state),
                TextColumn::make('content_type')
                    ->label('Typ')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => app(ContentBlueprintRegistry::class)
                        ->labelFor((string) $state, $tenant?->site_key))
                    ->visible(count(static::getContentTypes()) !== 1),
                TextColumn::make('path')
                    ->label('Pfad')
                    ->searchable()
                    ->visible($routable),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->visible(! $routable),
                TextColumn::make('resolved_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ContentStatus::tryFrom((string) $state)?->label() ?? (string) $state)
                    ->color(fn ($state): string => ContentStatus::tryFrom((string) $state)?->color() ?? 'gray'),
                Drafts::tableBadgeColumn(Cms::contentModel()),
            ])
            ->filters(static::tableFilters())
            ->recordActions([
                ActionGroup::make([
                    Action::make('open')
                        ->label('Öffnen')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        // Own path only — a row action links the record itself, never
                        // the parent page a non-routable type is embedded in (that is
                        // the topbar button's fallback, not this one's). Visibility is
                        // keyed on the URL, not on the path: without a `content.show`
                        // route there is nothing to link, and an href-less action would
                        // just sit in the menu doing nothing.
                        ->url(fn (Content $record): ?string => FrontendUrl::forPath($record->resolvedPath()))
                        ->openUrlInNewTab()
                        ->visible(fn (Content $record): bool => filled(FrontendUrl::forPath($record->resolvedPath()))),
                    EditAction::make(),
                    ReplicateAction::make()
                        ->label('Duplizieren')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        // Never copy a pending draft stash into the replica —
                        // its edit page would load the SOURCE's draft over the
                        // fresh title/path entered in this modal.
                        ->excludeAttributes(['draft'])
                        // Record-based blueprint resolution: the catch-all has
                        // no static blueprint, but the record being duplicated
                        // knows its type (slug-only modal for non-routable types).
                        ->schema(fn (Model $record): array => [
                            static::getDuplicateInput($tenant, static::blueprintForRecord($record)),
                        ])
                        ->mutateRecordDataUsing(function (array $data, Model $record): array {
                            // Prefill the modal: append "(Kopie)" to the title and derive a
                            // fresh, collision-free path/slug from it (the user can override).
                            $blueprint = static::blueprintForRecord($record);
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
                    static::warnAboutOrphans(DeleteAction::make()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Form-state key holding the id of the redirect the editor agreed to remove. */
    public const TAKEOVER_CONSENT_FIELD = 'release_redirect_id';

    /**
     * Say what a delete does to the pages underneath, before it happens.
     *
     * `parent_id` is `nullOnDelete`: the children survive at the root, and they KEEP their
     * old address — PathGenerator's authored branch hands back a stored path in full once
     * no prefix and no parent own any of it, so re-saving them changes nothing. That is
     * the problem, not a rescue: the page goes on living under a namespace that no longer
     * belongs to anything, `cms:paths:check` cannot see it because that path is a fixpoint
     * of the generator, and whoever later creates a page at the vacated address becomes an
     * unrelated stranger owning its prefix.
     *
     * Single deletes only. A DeleteBulkAction cannot be asked for its selection outside
     * its own run without opting the action into accessSelectedRecords(), which also
     * changes how the delete itself is executed — too much to pay for a modal sentence,
     * and a multi-select is a deliberate gesture rather than the accident this warns about.
     */
    public static function warnAboutOrphans(DeleteAction $action): DeleteAction
    {
        return $action->modalDescription(fn (?Model $record): ?string => static::orphanWarning($record));
    }

    /**
     * The confirmation copy for a delete that would strand pages, or null when it strands
     * none — in which case Filament keeps its own wording.
     */
    protected static function orphanWarning(?Model $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $orphans = app(ContentTree::class)->orphansOf(
            $record->getAttribute('tenant_id'),
            [$record->getKey()],
        );

        if ($orphans->isEmpty()) {
            return null;
        }

        $titles = $orphans->take(5)->map(fn (Model $orphan): string => sprintf('„%s“', $orphan->getAttribute('title')));

        if ($orphans->count() > $titles->count()) {
            $titles->push(sprintf('und %d weitere', $orphans->count() - $titles->count()));
        }

        $list = $titles->join(', ');

        return $orphans->count() === 1
            ? "Darunter liegt noch eine Seite: {$list}. Sie wird nicht mitgelöscht und behält ihre Adresse — "
                .'die dann unter einem Pfad liegt, den es nicht mehr gibt. Möchten Sie das wirklich tun?'
            : "Darunter liegen noch {$orphans->count()} Seiten: {$list}. Sie werden nicht mitgelöscht und "
                .'behalten ihre Adressen — die dann unter einem Pfad liegen, den es nicht mehr gibt. '
                .'Möchten Sie das wirklich tun?';
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

        $contentTypes = static::getContentTypes();

        if ($contentTypes !== []) {
            $query->whereIn('content_type', $contentTypes);
        }

        $requestedType = static::getRequestedContentType();

        if ($requestedType !== null) {
            $query->where('content_type', $requestedType);
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
     * How many of the record's ancestors this table itself lists — drives the
     * "↳" title indent. The arrow visually attaches a child to its parent ROW,
     * so it only makes sense when that parent can appear in the same listing:
     * the unrestricted pages tree indents nested pages, while a type-restricted
     * listing (machines, categories) or a parent-scoped children view shows a
     * flat set and gets no indent. Mirrors the getEloquentQuery() restrictions;
     * capped at 4 levels like the visual indent always was.
     */
    public static function listedAncestorDepth(Model $record): int
    {
        // Parent-scoped view: only ONE parent's children are listed — the
        // parent row itself is never among them.
        if (static::supportsParentScopedListing()
            && static::resolveRequestedParentId(app(CurrentTenant::class)->get()) !== null) {
            return 0;
        }

        $types = static::getContentTypes();
        $requestedType = static::getRequestedContentType();
        $depth = 0;
        $ancestor = $record->parent;

        while ($ancestor !== null && $depth < 4) {
            $listed = ($types === [] || in_array($ancestor->content_type, $types, true))
                && ($requestedType === null || $ancestor->content_type === $requestedType);

            if (! $listed) {
                break;
            }

            $depth++;
            $ancestor = $ancestor->parent;
        }

        return $depth;
    }

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
     * The title/slug row: the title/slug input with the content-type field: the type is a hidden field
     * pinned to the default type unless the type choice is enabled for the tenant's
     * site ({@see contentTypeSelectEnabled()}) — then the Seiten-Typ select sits
     * BESIDE the title input (right column).
     *
     * @param  string|null  $currentType  the type the form is opened on ({@see currentContentType()})
     * @return array<int, Component>
     */
    protected static function titleRowComponents(?Tenant $tenant, ?string $currentType = null): array
    {
        $typeComponents = static::getContentTypeFormComponents(static::getContentTypeOptions(), $currentType);
        $title = static::buildTitleWithSlugInput($tenant);

        // Sits under the Pfad field, visible only while a redirect holds the address this
        // form would store — the one address decision that is genuinely the editor's.
        // The wrapper owns the visibility, not the action inside it: the probe behind the
        // question costs a path composition plus a query, and every Livewire round-trip
        // would otherwise pay for it twice.
        $takeOver = SchemaActions::make([static::takeOverAddressAction($tenant)])
            ->key('take-over-address')
            ->visible(fn (Get $get, ?Model $record): bool => static::addressHeldByRedirect($get, $record, $tenant, (string) $get('path')) !== null)
            ->columnSpanFull();

        // Where the takeover records its consent until a save acts on it. Never dehydrated:
        // it is not an attribute, and it must not travel into a draft stash either — an
        // address freed weeks later, by a click nobody remembers, is worse than being asked
        // again at the moment it happens.
        $takeOverConsent = Hidden::make(static::TAKEOVER_CONSENT_FIELD)->dehydrated(false);

        $select = collect($typeComponents)->first(fn ($component): bool => $component instanceof Select);

        if ($select === null) {
            return [...$typeComponents, $title, $takeOver, $takeOverConsent];
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
            $takeOver,
            $takeOverConsent,
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
     * A form opened on a type the select does NOT offer keeps the hidden field, however
     * many types the site offers: the select can neither show that value nor validate
     * it, so the site's SECOND offered type would make every non-offered type
     * unsaveable ("Der gewählte Wert ist ungültig" on a type the record already has)
     * and reduce the ?type= deep-link — the only way to reach those types — to a
     * silent fallback to the default one.
     *
     * @param  array<string, string>  $contentTypeOptions
     * @param  string|null  $currentType  the type the form is opened on ({@see currentContentType()})
     * @return array<int, Component>
     */
    protected static function getContentTypeFormComponents(array $contentTypeOptions, ?string $currentType = null): array
    {
        $selectable = static::selectableContentTypeOptions($contentTypeOptions);

        if (count($selectable) <= 1 || ($currentType !== null && ! array_key_exists($currentType, $selectable))) {
            return [
                Hidden::make('content_type')
                    ->default($currentType ?? static::initialContentType())
                    ->required(),
            ];
        }

        return [
            Select::make('content_type')
                ->label('Seiten-Typ')
                ->required()
                ->default(static::initialContentType())
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
     * The content type a new record initially selects.
     *
     * Honors the `?type=` deep-link (so "… anlegen" from a type-scoped list pre-selects
     * that type), falling back to {@see defaultContentType()}. A requested type the
     * Seiten-Typ select does not offer never reaches this method — it pins the hidden
     * field instead ({@see getContentTypeFormComponents()}), which is what keeps
     * non-routable types reachable only via the catch-all creatable at all.
     */
    protected static function initialContentType(): ?string
    {
        return static::getRequestedContentType() ?? static::defaultContentType();
    }

    /**
     * The content type the form is opened on: the edited record's own type, the type a
     * create form already holds, or the `?type=` deep-link it was reached through. Null
     * when none of them says — then the type field falls back to
     * {@see initialContentType()}.
     *
     * Read off the schema rather than through a closure because it decides which COMPONENT
     * backs the type, and that is settled as the form tree is built. Which is also why the
     * answer must not depend on the query string alone: a Livewire update rebuilds the
     * schema on a request that carries no `?type=`, and a field that flipped from Hidden to
     * Select between the first render and the save would reject the very type the
     * deep-link pinned — leaving a type reachable ONLY through that link impossible to
     * create. The component's own state is what carries the type across those round trips.
     */
    protected static function currentContentType(Schema $schema): ?string
    {
        // Edit forms carry the record, create forms only the model class — for which
        // getRecord() answers null.
        $record = $schema->getRecord();

        if ($record instanceof Model) {
            return $record->getAttribute('content_type');
        }

        $held = data_get($schema->getLivewire(), 'data.content_type');

        return filled($held) ? (string) $held : static::getRequestedContentType();
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
        // The fallback has to be the type the field is SEEDED with, not whichever type
        // blueprint discovery happens to list first — otherwise adding a blueprint
        // silently re-points every reactive closure that gates on the selected type.
        return $get('content_type') ?: static::defaultContentType();
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

    protected static function buildTitleWithSlugInput(?Tenant $tenant): Grid|FusedGroup
    {
        $blueprint = static::resolveFormBlueprint();

        // Non-routable types have no path/URL — edit a plain, tenant-unique slug instead
        // of the "Pfad" field (paired with the GeneratesPathAndSlug saving logic, which
        // keeps path null and the slug populated).
        if ($blueprint !== null && ! $blueprint->isRoutable()) {
            return static::getSlugOnlyInput($tenant);
        }

        if ($blueprint !== null) {
            return static::getRoutableTitleInput($tenant, $blueprint);
        }

        // Multi-type catch-all: no static blueprint — mirror the single-type
        // choice REACTIVELY on the selected content type. Both variants share
        // the `title` state path; only the visible one dehydrates it (and its
        // own path/slug field), so a non-routable selection edits the slug and
        // never demands the routable variant's required path.
        $isRoutable = static::formIsRoutable();

        return Grid::make(1)
            ->columnSpanFull()
            ->schema([
                static::getRoutableTitleInput($tenant, null)
                    ->visible($isRoutable),
                static::getSlugOnlyInput($tenant)
                    ->visible(fn (Get $get): bool => ! $isRoutable($get)),
            ]);
    }

    /**
     * The routable title + "Pfad" variant (URL preview, path slugifier).
     */
    protected static function getRoutableTitleInput(?Tenant $tenant, ?ContentBlueprint $blueprint): FusedGroup
    {
        $config = static::titleSlugConfig();

        $pathPrefix = $config['urlPath'] ?? $blueprint?->urlPathPrefix();

        // Slug stores the full path including the leading "/" (e.g. "/category/item"),
        // which matches PathGenerator::normalize() and keeps the preview consistent
        // before and after save without form-state mutation.
        $urlPath = '';

        $urlVisitLinkVisible = $config['urlVisitLinkVisible']
            ?? ($blueprint !== null
                ? $blueprint->isRoutable()
                : fn (?Content $record): bool => filled($record?->resolvedPath()));

        // One resolvedPath() per call: it clones the record and re-runs the
        // PathGenerator, which walks the ancestor chain with a query per level —
        // and the slug input evaluates this closure several times per render.
        $urlVisitLinkRoute = $config['urlVisitLinkRoute']
            ?? fn (?Content $record): ?string => FrontendUrl::forPath($record?->resolvedPath());

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
        string|Closure|null $urlPath = '/',
        ?string $pathPrefix = null,
        bool|Closure $urlVisitLinkVisible = false,
        ?Closure $urlVisitLinkRoute = null,
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
            slugRules: static::getPathRules($tenant),
            slugRuleUniqueParameters: static::getPathRuleUniqueParameters($tenant),
            slugRuleRegex: '/^[a-z0-9\-\_\/]*$/',
            slugSlugifier: static::pathSlugifier($pathPrefix),
        )->columnSpanFull();
    }

    /**
     * Slugifier for the path field: strips an existing prefix so repeated edits don't
     * double it (e.g. "/projekte/kanalbau" → "kanalbau" → "/projekte/kanalbau") and
     * re-applies the blueprint's urlPathPrefix.
     *
     * Slugified SEGMENT BY SEGMENT, because Str::slug() drops "/" without replacement and
     * the field routinely holds a whole path — a nested page's own rebase writes one in
     * ({@see rebasePathOnParentChange()}). Run over the whole string, one blur turned
     * "/mietpark/anbauteile/lasthaken" into "/mietparkanbauteilelasthaken", and that is
     * what got saved: no error, no redirect, the page gone from its URL.
     */
    protected static function pathSlugifier(?string $pathPrefix): Closure
    {
        $normalizedPrefix = $pathPrefix ? trim($pathPrefix, '/') : null;

        return static function (string $text) use ($normalizedPrefix): string {
            $text = ltrim($text, '/');

            if ($normalizedPrefix && str_starts_with($text, $normalizedPrefix.'/')) {
                $text = substr($text, strlen($normalizedPrefix) + 1);
            }

            $slug = collect(explode('/', $text))
                ->map(fn (string $segment): string => Str::slug($segment))
                ->filter()
                ->implode('/');

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
            slugRuleUniqueParameters: static::getSlugRuleUniqueParameters($tenant),
            slugRuleRegex: '/^[a-z0-9\-\_]*$/',
            slugSlugifier: fn (string $text): string => Str::slug($text),
        )->columnSpanFull();
    }

    /**
     * Uniqueness parameters for the `slug` field of a non-routable type: tenant scoping,
     * nothing else.
     *
     * Deliberately NOT {@see getPathRuleUniqueParameters()}. That one switches itself off
     * whenever the typed value is not the path that would be stored, and it asks `path` to
     * decide — which on the catch-all is filled even for a non-routable record, whose
     * stored path is null. Sharing it turned off the only guard a tenant-unique slug has,
     * and two records could take the same one without a word.
     *
     * @return array<string, mixed>
     */
    protected static function getSlugRuleUniqueParameters(?Tenant $tenant, bool $ignoreCurrentRecord = true): array
    {
        $parameters = [
            'modifyRuleUsing' => fn (Unique $rule): Unique => $rule->where('tenant_id', $tenant?->getKey()),
        ];

        if ($ignoreCurrentRecord) {
            $parameters['ignorable'] = fn (?Model $record): ?Model => $record;
        }

        return $parameters;
    }

    /**
     * The record's blueprint by its stored content type — the reliable lookup
     * for record-bound actions on the multi-type catch-all (falls back to the
     * resource's static blueprint for single-type resources).
     */
    protected static function blueprintForRecord(Model $record): ?ContentBlueprint
    {
        return static::selectedBlueprint($record->getAttribute('content_type'))
            ?? static::resolveFormBlueprint();
    }

    /**
     * Title + path (or slug, for non-routable types) input for the Duplizieren modal.
     * Unlike the form input it shows no URL host/visit link, and its uniqueness rule
     * does NOT ignore the source record — the copy must get its own path/slug.
     */
    protected static function getDuplicateInput(?Tenant $tenant, ?ContentBlueprint $blueprint = null): FusedGroup
    {
        $blueprint ??= static::resolveFormBlueprint();
        $routable = $blueprint?->isRoutable() ?? true;

        return TitleWithSlugInput::make(
            fieldTitle: 'title',
            fieldSlug: $routable ? 'path' : 'slug',
            urlPath: '',
            urlHostVisible: false,
            urlVisitLinkVisible: false,
            titleLabel: 'Titel',
            slugLabel: $routable ? 'Pfad' : 'Slug',
            slugRules: $routable
                ? static::getPathRules($tenant)
                : ['required'],
            slugRuleUniqueParameters: $routable
                ? static::getPathRuleUniqueParameters($tenant, ignoreCurrentRecord: false)
                : static::getSlugRuleUniqueParameters($tenant, ignoreCurrentRecord: false),
            slugRuleRegex: $routable ? '/^[a-z0-9\-\_\/]*$/' : '/^[a-z0-9\-\_]*$/',
            slugSlugifier: $routable
                ? static::pathSlugifier($blueprint?->urlPathPrefix())
                : fn (string $text): string => Str::slug($text),
        )->columnSpanFull();
    }

    /**
     * Rules for the "Pfad" field.
     *
     * The value in the field is not necessarily the path that gets stored: for a
     * parent-driven blueprint the saving hook rebases it under the selected parent
     * ({@see PathGenerator}). A typed "/seilwinde" under the category "/mietpark/anbauteile"
     * becomes "/mietpark/anbauteile/seilwinde", which a sibling may already own — a
     * collision no rule on the typed value can see, and one the unique index used to answer
     * with an uncaught UniqueConstraintViolationException: a 500 in the panel, the edit
     * lost, and the record permanently unsaveable because every retry produced the same
     * path.
     *
     * The rule therefore builds the record this form would save and hands it to
     * {@see PathConflicts} — the same service the model's saving hook uses as its backstop,
     * so the panel and every other writer answer the question identically. Re-deriving the
     * composition here instead — the form's own rebase ({@see rebasePathOnParentChange()})
     * covers only the parent-driven branch — would reject legal saves for every blueprint
     * the generator treats differently — a urlPathPrefix type, where the prefix wins over the hierarchy,
     * or a record with no parent, whose typed path is kept in full.
     *
     * Doing it here as well as in the hook is what puts the message ON the Pfad field: a
     * ValidationException raised from the model lands in the component's error bag under a
     * key no form component owns, so the editor would be left with a save that silently
     * does nothing.
     *
     * @return array<int, string|Closure>
     */
    protected static function getPathRules(?Tenant $tenant): array
    {
        return [
            'required',
            static::storedPathIsFreeRule($tenant),
            static::addressIsFreeRule($tenant),
            static::subtreeAddressIsFreeRule($tenant),
        ];
    }

    /**
     * Closure rule rejecting a path an active REDIRECT stands on.
     *
     * A redirect answers before the content lookup ever runs, so a page put on such an
     * address would be unreachable there. The editor meets that the same way they meet a
     * page already holding the address — an error on the Pfad field, with the takeover
     * offered as a hint action beside it ({@see takeOverAddressAction()}) — rather than in
     * a dialog between them and the save.
     */
    protected static function addressIsFreeRule(?Tenant $tenant): Closure
    {
        return static fn (Get $get, ?Model $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $tenant): void {
            $standing = static::addressHeldByRedirect($get, $record, $tenant, (string) $value);

            if ($standing === null) {
                return;
            }

            $fail(sprintf(
                'Auf „%s“ zeigt eine Weiterleitung nach „%s“ — die Seite wäre dort nicht erreichbar. '
                .'Über „Adresse übernehmen“ wird sie entfernt.',
                $standing->from_path,
                $standing->resolvedTarget() ?? '—',
            ));
        };
    }

    /**
     * Closure rule rejecting a move that would drag a DESCENDANT under a redirect.
     *
     * Separate from {@see addressIsFreeRule()} because the takeover cannot answer it: the
     * offending address belongs to another record, so there is nothing beside the Pfad
     * field to press. The editor is told which page and which address, and decides.
     */
    protected static function subtreeAddressIsFreeRule(?Tenant $tenant): Closure
    {
        return static fn (Get $get, ?Model $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $tenant): void {
            $probe = static::pathProbeFor($get, $record, $tenant, (string) $value);

            if (! $probe->exists || ! $probe->isDirty('path')) {
                return;
            }

            $shadowed = app(ContentRenameRedirects::class)->firstShadowedInSubtree($probe);

            if ($shadowed === null) {
                return;
            }

            $fail(sprintf(
                '„%s“ würde dadurch auf „%s“ verschoben, und dorthin zeigt bereits eine Weiterleitung '
                .'nach „%s“ — die Seite wäre dort nicht erreichbar.',
                $shadowed['record']->getAttribute('title'),
                $shadowed['redirect']->from_path,
                $shadowed['redirect']->resolvedTarget() ?? '—',
            ));
        };
    }

    /**
     * The redirect that would shadow the address this form state would store, if any.
     *
     * Answered only for a save that newly puts the record there. A record ALREADY sitting
     * on a shadowed address is a state the CMS supports on purpose — a redirect wins over
     * content, which is documented redirection.me parity — and reporting it on every
     * validation would make that page permanently unsaveable: an editor could not so much
     * as fix its title without being told to delete somebody's curated redirect.
     *
     * {@see PathConflicts} draws the same line for content-versus-content, with the same
     * `exists && ! isDirty('path')` test.
     */
    protected static function addressHeldByRedirect(Get $get, ?Model $record, ?Tenant $tenant, string $path): ?Redirect
    {
        $probe = static::pathProbeFor($get, $record, $tenant, $path);

        if ($probe->exists && ! $probe->isDirty('path')) {
            return null;
        }

        $standing = app(ContentRenameRedirects::class)->shadowing(
            $tenant?->getKey() ?? $probe->getAttribute('tenant_id'),
            $probe->getAttribute('path'),
            $probe,
        );

        if ($standing === null) {
            return null;
        }

        // Already answered: the save will remove this very row. Asking again on every
        // round-trip would leave the error standing on a field the editor has finished
        // with. Matched by id, so a DIFFERENT redirect appearing on the same address is
        // still reported rather than silently covered by an old answer.
        return (int) $get(static::TAKEOVER_CONSENT_FIELD) === (int) $standing->getKey() ? null : $standing;
    }

    /**
     * "Adresse übernehmen" beside the Pfad field: records that the editor wants the
     * redirect standing on their new address gone, so the page becomes reachable there.
     *
     * It records rather than releases. "Entwurf speichern" and "Vorschau" run the same
     * form and the same validation as "Änderungen anwenden", but change nothing live —
     * freeing the address there would kill a working URL for a move that may never happen,
     * and a released row is a tombstone the 404 resolver will not offer back. The release
     * happens where the move does ({@see releaseTakenOverAddress()}).
     */
    protected static function takeOverAddressAction(?Tenant $tenant): Action
    {
        return Action::make('takeOverAddress')
            ->label('Adresse übernehmen')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Weiterleitung entfernen')
            ->modalDescription('Beim Speichern wird die Adresse freigegeben und nicht von selbst wieder vorgeschlagen.')
            ->action(function (Get $get, Set $set, ?Model $record) use ($tenant): void {
                $standing = static::addressHeldByRedirect($get, $record, $tenant, (string) $get('path'));

                if ($standing === null) {
                    return;
                }

                // The consent names the ROW it was given for, not just the address: the
                // release then cannot reach a different redirect that appears there later.
                $set(static::TAKEOVER_CONSENT_FIELD, $standing->getKey());

                Notification::make()
                    ->success()
                    ->title('Adresse wird übernommen')
                    ->body(sprintf('Die Weiterleitung von „%s“ wird beim Speichern entfernt.', $standing->from_path))
                    ->send();
            });
    }

    /**
     * Carry out a takeover the editor consented to, once the record really sits on the
     * address.
     *
     * Driven from Filament's RecordCreated/RecordUpdated events ({@see CmsServiceProvider}),
     * not from afterSave()/afterCreate(): these pages exist to be extended, and a subclass
     * overriding a common hook without calling the parent would leave the consent recorded
     * and never executed — the editor sees the success toast and the page stays shadowed.
     * The draft clearing next door is wired the same way for the same reason.
     *
     * Three things have to be true, and each of them is a real case:
     * - the save was meant as an APPLY. "Vorschau" on an unpublished record runs the same
     *   full save ({@see ManagesDrafts::saveForPreview()}), and destroying a working
     *   redirect for a look at a page nobody can see yet is not what was asked.
     * - the redirect is still the one the editor was shown. Matched by id, so an admin's
     *   newly curated row on the same address is never what gets deleted.
     * - the record really took that address. A consent given and then typed away from is
     *   spent, not carried to wherever the record ended up.
     *
     * The consent is cleared either way: it authorises one release, not a standing licence
     * that every later save of the same open form acts on.
     */
    public static function releaseTakenOverAddress(mixed $record, mixed $page = null): void
    {
        if (! $record instanceof Model || ! $page instanceof LivewireComponent) {
            return;
        }

        $consentId = $page->data[static::TAKEOVER_CONSENT_FIELD] ?? null;

        if (blank($consentId)) {
            return;
        }

        if (method_exists($page, 'isSavingForPreview') && $page->isSavingForPreview()) {
            return;
        }

        $page->data[static::TAKEOVER_CONSENT_FIELD] = null;

        $redirects = app(ContentRenameRedirects::class);

        $standing = $redirects->shadowing(
            $record->getAttribute('tenant_id'),
            $record->getAttribute('path'),
            $record,
        );

        if ($standing === null || (int) $standing->getKey() !== (int) $consentId) {
            return;
        }

        $redirects->release($standing);

        Notification::make()
            ->success()
            ->title('Adresse übernommen')
            ->body(sprintf('Die Weiterleitung von „%s“ wurde entfernt.', $standing->from_path))
            ->send();
    }

    /**
     * Closure rule rejecting a path whose STORED form — or the path any descendant would be
     * cascaded onto — is already taken by another record of the same tenant.
     */
    protected static function storedPathIsFreeRule(?Tenant $tenant): Closure
    {
        return static fn (Get $get, ?Model $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $tenant): void {
            $probe = static::pathProbeFor($get, $record, $tenant, (string) $value);

            $conflict = app(PathConflicts::class)->firstConflict($probe);

            if ($conflict === null) {
                return;
            }

            $owner = $conflict['owner']->getAttribute('title');

            if ($conflict['record'] !== $probe) {
                $fail(sprintf(
                    '„%s“ würde dadurch auf „%s“ verschoben, und diesen Pfad belegt bereits „%s“.',
                    $conflict['record']->getAttribute('title'),
                    $conflict['path'],
                    $owner,
                ));

                return;
            }

            $fail($conflict['path'] === $value
                ? sprintf('Diesen Pfad belegt bereits „%s“.', $owner)
                : sprintf('Gespeichert wird daraus „%s“, und den belegt bereits „%s“.', $conflict['path'], $owner));
        };
    }

    /**
     * The path a save would store for the current form state — asked of the generator
     * itself ({@see GeneratesPathAndSlug::resolvedPath()}) on a throwaway model, so
     * routability, urlPathPrefix and normalization all come from the one place that
     * decides them.
     *
     * A record being edited is probed as a CLONE of itself, so it keeps its key and its
     * original attributes: that is what lets PathConflicts tell a rename from a create,
     * exclude the record's own row, and walk the subtree the rename would drag along.
     */
    protected static function pathProbeFor(Get $get, ?Model $record, ?Tenant $tenant, string $path): Content
    {
        // The record's own type outranks the resource default: resolveSelectedContentType()
        // falls back to that default, so on a form without a content_type field — the
        // Duplizieren modal — it would answer for the wrong blueprint and never reach a
        // `??` fallback behind it.
        $contentType = $get('content_type')
            ?: $record?->getAttribute('content_type')
            ?: static::defaultContentType();

        // The parent field owns the answer whenever the form carries one — including when
        // the editor cleared it, which moves the record to the root. Only a form without
        // the field at all falls back to the stored parent.
        $parentId = $get('parent_id');

        if ($parentId === null && static::getAllowedParentTypes($contentType) === []) {
            $parentId = $record?->getAttribute('parent_id');
        }

        $model = Cms::contentModel();

        /** @var Content $content */
        $content = $record instanceof Model ? clone $record : new $model;

        $content->forceFill([
            'tenant_id' => $tenant?->getKey() ?? $record?->getAttribute('tenant_id'),
            'content_type' => $contentType,
            'parent_id' => filled($parentId) ? (int) $parentId : null,
            'title' => $get('title') ?? $record?->getAttribute('title'),
            'path' => $path,
        ]);

        // Spares resolvedPath() the tenant lookup; the clone it works on keeps the relation.
        if ($tenant !== null) {
            $content->setRelation('tenant', $tenant);
        }

        // Mirror the saving hook, which assigns the generator's answer back onto the
        // record. Only then does isDirty('path') mean "this save would move the stored
        // path" — the typed value alone can equal the stored one while the generated path
        // differs, which is exactly the drifted record this whole guard exists for.
        $content->forceFill(['path' => $content->resolvedPath()]);

        return $content;
    }

    /**
     * The path the current form state would store, or null for a type that stores none.
     */
    protected static function pathThisFormWouldStore(Get $get, ?Model $record, ?Tenant $tenant, string $path): ?string
    {
        return static::pathProbeFor($get, $record, $tenant, $path)->getAttribute('path');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getPathRuleUniqueParameters(?Tenant $tenant, bool $ignoreCurrentRecord = true): array
    {
        $parameters = [
            'modifyRuleUsing' => function (Unique $rule, Get $get, ?Model $record) use ($tenant): Unique {
                $rule->where('tenant_id', $tenant?->getKey());

                // TitleWithSlugInput always attaches this rule, and it can only check the
                // typed value. Where that value is not the stored path, a hit on it means
                // nothing — a page named "Kontakt" under a category would be rejected for a
                // top-level /kontakt it will never occupy. Let the effective-path rule in
                // {@see getPathRules()} answer alone.
                if (static::pathThisFormWouldStore($get, $record, $tenant, (string) $get('path')) !== $get('path')) {
                    $rule->where(fn (QueryBuilder $query) => $query->whereRaw('1 = 0'));
                }

                return $rule;
            },
        ];

        if ($ignoreCurrentRecord) {
            $parameters['ignorable'] = fn (?Model $record): ?Model => $record;
        }

        return $parameters;
    }

    // -------------------------------------------------------------------------
    //  Type Scoping
    // -------------------------------------------------------------------------

    /**
     * A `?type=` query param that narrows the list to a single managed content type.
     *
     * Powers the listing block's "… verwalten" deep-link: the catch-all resource
     * manages many types at once, so the link scopes its index to the listed type
     * (a dedicated single-type resource is already scoped and the param is a no-op).
     * Ignored unless the value is one of THIS resource's managed types, so the param
     * can never widen a resource beyond what it owns (an unset/'' param is never a
     * managed type, so it falls through to null).
     */
    public static function getRequestedContentType(): ?string
    {
        $requestedType = static::scopedQueryParam('type');

        return in_array($requestedType, static::getContentTypes(), true) ? $requestedType : null;
    }

    /**
     * Reads a list-scoping query param, surviving Livewire table updates.
     *
     * A Livewire table update (paginate/sort/search) POSTs to /livewire/update with no
     * query string, which would silently drop the scope. On those requests only, the
     * value is recovered from the Referer (the full list URL) — the same source Livewire
     * uses to restore #[Url] state — so a fresh navigation is never mis-scoped by a
     * stale Referer.
     */
    protected static function scopedQueryParam(string $name): string
    {
        $value = request()->string($name)->toString();

        if ($value === '' && request()->hasHeader('X-Livewire')) {
            parse_str((string) parse_url((string) request()->headers->get('referer'), PHP_URL_QUERY), $refererQuery);
            $value = is_string($refererQuery[$name] ?? null) ? $refererQuery[$name] : '';
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    //  Parent Scoping
    // -------------------------------------------------------------------------

    protected static function resolveRequestedParentId(?Tenant $tenant, ?string $contentType = null): ?int
    {
        $requestedParentId = (int) (static::scopedQueryParam('parent') ?: static::scopedQueryParam('parent_id'));

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
