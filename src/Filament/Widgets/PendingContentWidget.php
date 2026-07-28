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
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Filament\Widgets\Concerns\ResolvesContentResourceUrls;
use Mmoollllee\Cms\Support\Preview\Drafts;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * The dashboard's to-do list: content that is waiting on someone.
 *
 * Three states qualify, and only these three:
 *
 * - **Entwurf** — an unapplied draft stash. Someone parked changes that are not
 *   live yet; the record needs a decision (apply or discard).
 * - **Geplant** — a future publish_from. Not a task, but time-bound, and an
 *   editor should be able to see what is queued without hunting through lists.
 * - **Abgelaufen** — publish_until has passed, so the page silently fell offline.
 *   Almost always unintentional.
 *
 * "Unveröffentlicht" (never published) is deliberately EXCLUDED: it is a stable,
 * intentional state, and on sites with many parked records it would bury the
 * three states above in noise. Its count still appears in the
 * {@see ContentOverviewWidget} summary line.
 *
 * The widget hides itself entirely when nothing qualifies ({@see canView()}) —
 * an empty to-do table is pure dashboard clutter.
 */
class PendingContentWidget extends TableWidget
{
    use ResolvesContentResourceUrls;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (app(CurrentTenant::class)->get() === null || ! Cms::modelsConfigured()) {
            return false;
        }

        return static::baseQuery()->clone()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Zu erledigen')
            ->description('Gespeicherte Entwürfe, geplante Veröffentlichungen und abgelaufene Inhalte.')
            ->query(fn (): Builder => static::baseQuery())
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('title')
                    ->label('Inhalt')
                    ->description(fn (Model $record): ?string => $this->typeLabelForRecord($record))
                    ->wrap(),
                TextColumn::make('state')
                    ->label('Zustand')
                    ->badge()
                    ->state(fn (Model $record): string => $this->stateLabel($record))
                    ->color(fn (Model $record): string => $this->stateColor($record))
                    ->width('10rem'),
                TextColumn::make('deadline')
                    ->label('Wann')
                    ->state(fn (Model $record): ?string => $this->deadlineFor($record))
                    ->placeholder('—')
                    ->width('14rem'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Bearbeiten')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Model $record): ?string => $this->resourceUrlForRecord($record, 'edit'))
                    ->visible(fn (Model $record): bool => $this->resourceUrlForRecord($record, 'edit') !== null),
            ])
            ->emptyStateHeading('Nichts offen')
            ->emptyStateDescription('Keine Entwürfe, keine geplanten oder abgelaufenen Inhalte.');
    }

    /**
     * Tenant content in one of the three actionable states.
     *
     * Ordered by publish_from so scheduled items read as a timeline; the draft
     * stashes (which have no meaningful window) sort by their own recency.
     */
    protected static function baseQuery(): Builder
    {
        $tenant = app(CurrentTenant::class)->get();

        /** @var class-string<Model> $model */
        $model = Cms::contentModel();

        // canView() gates on a tenant, but the query closure runs per Livewire
        // request — an explicit empty result beats accidentally-empty SQL.
        if ($tenant === null) {
            return $model::query()->whereRaw('1 = 0');
        }

        $supportsDrafts = Drafts::supported($model);

        return $model::query()
            ->where('tenant_id', $tenant->getKey())
            ->where(function (Builder $query) use ($supportsDrafts): void {
                $query
                    ->where(fn (Builder $sub) => $sub->scheduled())
                    ->orWhere(fn (Builder $sub) => $sub->expired());

                if ($supportsDrafts) {
                    $query->orWhere(fn (Builder $sub) => $sub->withDraft());
                }
            })
            ->orderByRaw('publish_from IS NULL DESC')
            ->orderBy('publish_from')
            ->orderByDesc('id');
    }

    /**
     * A record can be several things at once (a scheduled page with a parked
     * draft). The stash wins: it is the only state that needs a human decision.
     */
    protected function stateLabel(Model $record): string
    {
        if (Drafts::pending($record)) {
            return 'Entwurf';
        }

        return $record->status()->label();
    }

    protected function stateColor(Model $record): string
    {
        if (Drafts::pending($record)) {
            return 'warning';
        }

        return $record->status()->color();
    }

    /** What the row's date column means depends on which state put it here. */
    protected function deadlineFor(Model $record): ?string
    {
        if (Drafts::pending($record)) {
            return $record->draftSavedAt()?->format('d.m.Y H:i');
        }

        return match ($record->status()) {
            ContentStatus::Scheduled => 'ab '.$record->publish_from?->format('d.m.Y H:i'),
            ContentStatus::Expired => 'seit '.$record->publish_until?->format('d.m.Y H:i'),
            default => null,
        };
    }
}
