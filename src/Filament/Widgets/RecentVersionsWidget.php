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
use Mmoollllee\Cms\Concerns\HasVersions;
use Mmoollllee\Cms\Filament\Widgets\Concerns\ResolvesContentResourceUrls;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Support\Versioning\Versions;
use Overtrue\LaravelVersionable\Version;

/**
 * Dashboard table of the tenant's most recent APPLIED changes — one row per
 * recorded version across contents and fragments ({@see HasVersions}),
 * with author, record and deep links to the editing resource / its revisions
 * page. Draft stashes never appear here by design (they record no version).
 */
class RecentVersionsWidget extends TableWidget
{
    use ResolvesContentResourceUrls;

    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

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
                    ->description(fn (Version $record): ?string => $this->typeLabelForRecord($this->recordFor($record))),
                TextColumn::make('user.name')
                    ->label('Von')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Bearbeiten')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Version $record): ?string => $this->resourceUrlForRecord($this->recordFor($record), 'edit'))
                    ->visible(fn (Version $record): bool => $this->resourceUrlForRecord($this->recordFor($record), 'edit') !== null),
                Action::make('revisions')
                    ->label('Revisionen')
                    ->icon(Heroicon::OutlinedClock)
                    ->url(fn (Version $record): ?string => $this->resourceUrlForRecord($this->recordFor($record), 'revisions'))
                    ->visible(fn (Version $record): bool => $this->resourceUrlForRecord($this->recordFor($record), 'revisions') !== null),
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

    /** The versioned record a row points at, or null once it was deleted. */
    protected function recordFor(Version $version): ?Model
    {
        return $version->versionable;
    }
}
