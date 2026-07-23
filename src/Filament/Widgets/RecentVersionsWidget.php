<?php

namespace Mmoollllee\Cms\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Fragment;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Content\ContentResourceLocator;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Support\Versioning\Versions;
use Overtrue\LaravelVersionable\Version;

/**
 * Dashboard table of the tenant's most recent APPLIED changes — one row per
 * recorded version across contents and fragments ({@see \Mmoollllee\Cms\Concerns\HasVersions}),
 * with author, record and deep links to the editing resource / its revisions
 * page. Draft stashes never appear here by design (they record no version).
 */
class RecentVersionsWidget extends TableWidget
{
    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';

    /**
     * Per-request memos: the table evaluates url()+visible() per action per
     * row, and each resolution walks the site-extension registry / the route
     * generator (same rationale as Drafts::supported's memo).
     *
     * @var array<string, ?string>
     */
    private array $resourceUrlMemo = [];

    /** @var array<string, ?string> */
    private array $resourceClassMemo = [];

    /** @var array<string, string> */
    private array $typeLabelMemo = [];

    public static function canView(): bool
    {
        return app(CurrentTenant::class)->get() !== null && static::versionedModels() !== [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Letzte Änderungen')
            ->description('Angewendete Änderungen an Inhalten und Fragmenten — Entwürfe erscheinen erst nach dem Anwenden.')
            ->query(fn (): Builder => $this->versionsQuery())
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Wann')
                    ->since()
                    ->dateTimeTooltip('d.m.Y H:i')
                    ->width('10rem'),
                TextColumn::make('versionable')
                    ->label('Inhalt')
                    ->state(fn (Version $record): string => $record->versionable?->title ?? 'Gelöschter Inhalt')
                    ->description(fn (Version $record): ?string => $this->typeLabelFor($record)),
                TextColumn::make('user.name')
                    ->label('Von')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Bearbeiten')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Version $record): ?string => $this->resourceUrlFor($record, 'edit'))
                    ->visible(fn (Version $record): bool => $this->resourceUrlFor($record, 'edit') !== null),
                Action::make('revisions')
                    ->label('Revisionen')
                    ->icon(Heroicon::OutlinedClock)
                    ->url(fn (Version $record): ?string => $this->resourceUrlFor($record, 'revisions'))
                    ->visible(fn (Version $record): bool => $this->resourceUrlFor($record, 'revisions') !== null),
            ])
            ->emptyStateHeading('Noch keine Änderungen')
            ->emptyStateDescription('Sobald Inhalte angewendet werden, erscheint hier die Historie.');
    }

    protected function versionsQuery(): Builder
    {
        /** @var class-string<Version> $versionModel */
        $versionModel = config('versionable.version_model', Version::class);

        $tenant = app(CurrentTenant::class)->get();

        // canView() gates on a tenant, but the query closure runs per Livewire
        // request — an explicit empty result beats accidentally-empty SQL.
        if ($tenant === null) {
            return $versionModel::query()->whereRaw('1 = 0');
        }

        return $versionModel::query()
            ->with(['user', 'versionable'])
            ->whereHasMorph(
                'versionable',
                static::versionedModels(),
                fn (Builder $query) => $query->where('tenant_id', $tenant->getKey()),
            )
            // id as tie-breaker: same-second timestamps (seeding, bulk edits)
            // would otherwise order nondeterministically.
            ->latest()
            ->orderByDesc('id');
    }

    /**
     * The registered CMS models that actually adopted HasVersions.
     *
     * @return list<class-string>
     */
    protected static function versionedModels(): array
    {
        $models = Cms::modelsConfigured() ? [Cms::contentModel(), Cms::fragmentModel()] : [];

        return array_values(array_filter($models, fn (?string $model): bool => Versions::supported($model)));
    }

    /** Blueprint label for contents, "Fragment" for fragments. */
    protected function typeLabelFor(Version $version): ?string
    {
        $record = $version->versionable;

        if ($record === null) {
            return null;
        }

        if ($record instanceof Fragment) {
            return FragmentResource::getModelLabel();
        }

        return $this->typeLabelMemo[(string) $record->content_type] ??= app(ContentBlueprintRegistry::class)->labelFor(
            (string) $record->content_type,
            app(CurrentTenant::class)->get()?->site_key,
        );
    }

    /**
     * URL to the managing resource's page for the version's record — the
     * type-specific site resource wins over the catch-all
     * (ContentResourceLocator), and inaccessible resources yield no link
     * (mirrors the listing block's canAccess() guard: a visible button must
     * not lead into a 403).
     */
    protected function resourceUrlFor(Version $version, string $page): ?string
    {
        $key = $version->getKey().':'.$page;

        if (! array_key_exists($key, $this->resourceUrlMemo)) {
            $this->resourceUrlMemo[$key] = $this->buildResourceUrl($version, $page);
        }

        return $this->resourceUrlMemo[$key];
    }

    protected function buildResourceUrl(Version $version, string $page): ?string
    {
        $record = $version->versionable;

        if ($record === null) {
            return null;
        }

        $resource = $this->resourceFor($record);

        if ($resource === null || ! $resource::hasPage($page) || ! $resource::canAccess()) {
            return null;
        }

        return $resource::getUrl($page, ['record' => $record]);
    }

    /** @return class-string|null */
    protected function resourceFor(Model $record): ?string
    {
        if ($record instanceof Fragment) {
            return FragmentResource::class;
        }

        $type = (string) $record->content_type;

        // array_key_exists, not ??= — a null result (unmanaged type) must be
        // memoized too, or every row retries the locator scan.
        if (! array_key_exists($type, $this->resourceClassMemo)) {
            $this->resourceClassMemo[$type] = app(ContentResourceLocator::class)->resolve(
                $type,
                app(CurrentTenant::class)->get(),
            );
        }

        return $this->resourceClassMemo[$type];
    }
}
