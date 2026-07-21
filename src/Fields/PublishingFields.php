<?php

namespace Mmoollllee\Cms\Fields;

use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Enums\ContentVisibility;

/**
 * Publishing fields: a `status` UI helper that drives the scheduling pair
 * (`publish_from` / `publish_until`), plus `visibility`. Shared by every content
 * type. The visibility default is blueprint-aware, so the consuming resource
 * injects it via {@see defaultVisibilityUsing()} rather than the kit reaching
 * into the Sites layer.
 *
 */
class PublishingFields extends FieldKit
{
    protected Closure|string|null $defaultVisibility = null;

    /**
     * Provide the (blueprint-aware) default for the visibility select.
     */
    public function defaultVisibilityUsing(Closure|string $default): static
    {
        $this->defaultVisibility = $default;

        return $this;
    }

    protected function fields(): array
    {
        return [
            // Bidirectional: choosing a status resets the publishing window below
            // (afterStateUpdated), and editing that window recomputes the status
            // (publish_from / publish_until → computeStatus()). Each branch sets a
            // window that computeStatus() maps back to the same status, so the pair
            // round-trips.
            'status' => Select::make('status')
                ->label('Status')
                ->options(ContentStatus::options())
                // `status` is virtual (no column), so Filament skips ->default() on
                // edit — it only seeds defaults when creating. formatStateUsing runs
                // on both create and edit hydration, deriving the initial value from
                // the record's publishing window so an existing published page loads
                // as "Veröffentlicht" instead of "Entwurf". A provided state wins:
                // the draft fill (ManagesDrafts) precomputes the status from the
                // DRAFT window, which may differ from the record's.
                ->formatStateUsing(fn ($state, $record): string => $state ?? $record?->status()->value ?? ContentStatus::Draft->value)
                ->selectablePlaceholder(false)
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    match ($state) {
                        'draft' => (function () use ($set): void {
                            $set('publish_from', null);
                            $set('publish_until', null);
                        })(),
                        'published' => (function () use ($set): void {
                            $set('publish_from', now()->format('Y-m-d H:i:s'));
                            $set('publish_until', null);
                        })(),
                        'scheduled' => (function () use ($set): void {
                            $set('publish_from', now()->addDay()->startOfDay()->format('Y-m-d H:i:s'));
                            $set('publish_until', null);
                        })(),
                        'expired' => (function () use ($set): void {
                            $set('publish_from', now()->subDay()->startOfDay()->format('Y-m-d H:i:s'));
                            $set('publish_until', now()->subDay()->endOfDay()->format('Y-m-d H:i:s'));
                        })(),
                        default => null,
                    };
                }),
            'publish_from' => DateTimePicker::make('publish_from')
                ->label('Veröffentlichen ab')
                ->seconds(false)
                ->live()
                ->hintAction(self::resetToSavedValueAction('publish_from'))
                ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => $set('status', self::computeStatus($state, $get('publish_until'))))
                ->afterContent(
                    Action::make('setPublishFromNow')
                        ->label('Jetzt')
                        ->link()
                        ->size('sm')
                        ->action(fn (Set $set, Get $get): mixed => $set('publish_from', now()->format('Y-m-d H:i:s')) ?? $set('status', self::computeStatus(now()->format('Y-m-d H:i:s'), $get('publish_until')))),
                ),
            'publish_until' => DateTimePicker::make('publish_until')
                ->label('Veröffentlichen bis')
                ->seconds(false)
                ->live()
                ->hintAction(self::resetToSavedValueAction('publish_until'))
                ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => $set('status', self::computeStatus($get('publish_from'), $state)))
                ->afterContent(
                    Action::make('setPublishUntilNow')
                        ->label('Jetzt')
                        ->link()
                        ->size('sm')
                        ->action(fn (Set $set, Get $get): mixed => $set('publish_until', now()->format('Y-m-d H:i:s')) ?? $set('status', self::computeStatus($get('publish_from'), now()->format('Y-m-d H:i:s')))),
                ),
            'visibility' => Select::make('visibility')
                ->label('Zugriff')
                ->required()
                ->options(ContentVisibility::options())
                ->default($this->defaultVisibility ?? ContentVisibility::Public->value)
                ->columnSpanFull(),
        ];
    }

    /**
     * A revert-icon hint action (top-right of the field label) for one of the two
     * datetime fields. It only appears while the field is "dirty" — i.e. its current
     * form value differs from the record's saved value — and resets the field back to
     * that saved value, recomputing the derived status from the resulting window.
     * On create there is no saved value, so the action reverts an entered value to empty.
     */
    protected static function resetToSavedValueAction(string $field): Action
    {
        return Action::make('reset_'.$field)
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->iconButton()
            ->color('gray')
            ->size('xs')
            ->label('Zurücksetzen')
            ->tooltip('Auf den gespeicherten Wert zurücksetzen')
            ->visible(fn (Get $get, $livewire): bool => self::isDirty($get($field), self::savedValue($livewire, $field)))
            ->action(function (Set $set, Get $get, $livewire) use ($field): void {
                $original = self::normalizeDateTime(self::savedValue($livewire, $field));

                $set($field, $original);
                $set('status', $field === 'publish_from'
                    ? self::computeStatus($original, $get('publish_until'))
                    : self::computeStatus($get('publish_from'), $original));
            });
    }

    /**
     * The record's saved value for a field, or null when there is no record yet
     * (create form) or the host page exposes none. Read from the page record rather
     * than the injected `$record` because action closures do not resolve it reliably.
     */
    protected static function savedValue(mixed $livewire, string $field): mixed
    {
        $record = (is_object($livewire) && method_exists($livewire, 'getRecord'))
            ? $livewire->getRecord()
            : null;

        return $record instanceof \Illuminate\Database\Eloquent\Model
            ? $record->getAttribute($field)
            : null;
    }

    /**
     * Whether a datetime form value differs from its saved counterpart, comparing on
     * a canonical string so display-format vs. Carbon differences don't read as dirty.
     */
    protected static function isDirty(mixed $current, mixed $original): bool
    {
        return self::normalizeDateTime($current) !== self::normalizeDateTime($original);
    }

    /**
     * Canonicalize a datetime (Carbon, string, or null/blank) to a `Y-m-d H:i`
     * string, or null when empty. Minute precision matches the pickers' granularity
     * (seconds are disabled), so a saved value's stray seconds don't read as dirty.
     */
    protected static function normalizeDateTime(mixed $value): ?string
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('Y-m-d H:i');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i');
    }

    /**
     * The status value for an arbitrary publishing window (Carbon, string or
     * null) — the public entry used by the draft fill to derive the virtual
     * `status` from a stashed window instead of the record's.
     */
    public static function statusForWindow(mixed $publishFrom, mixed $publishUntil): string
    {
        return self::computeStatus(
            self::normalizeDateTime($publishFrom),
            self::normalizeDateTime($publishUntil),
        );
    }

    /**
     * Derive the status value from the scheduling window.
     */
    protected static function computeStatus(?string $publishFrom, ?string $publishUntil): string
    {
        if ($publishFrom === null || $publishFrom === '') {
            return ContentStatus::Draft->value;
        }

        if (Carbon::parse($publishFrom)->isFuture()) {
            return ContentStatus::Scheduled->value;
        }

        if ($publishUntil !== null && $publishUntil !== '' && Carbon::parse($publishUntil)->isPast()) {
            return ContentStatus::Expired->value;
        }

        return ContentStatus::Published->value;
    }
}
