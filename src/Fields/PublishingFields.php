<?php

namespace Mmoollllee\Cms\Fields;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Enums\ContentVisibility;

/**
 * Publishing fields: a single "Veröffentlicht" toggle steering the scheduling
 * pair (`publish_from` / `publish_until`). Shared by every content type.
 *
 * The kit deliberately holds no status display: what the entered combination
 * DOES belongs into the hosting section's HEADER, where it reads as one
 * sentence above the controls instead of competing with them for a grid cell.
 * The host section wires it via {@see sectionDescription()}.
 *
 * One axis, one direction: the toggle and the window fields WRITE, the header
 * sentence only READS ({@see ContentStatus::forWindow()}) — scheduling and
 * expiry are outcomes of the entered window, not selectable states. This
 * replaces the former four-option status select whose bidirectional
 * select↔window coupling invited accidental unpublishing; the sentence
 * explains the effect instead of naming a state, so editors need not decode
 * what "Geplant" implies.
 *
 * `is_published` is virtual (dehydrated(false)); its Boolean state cast turns
 * an unfilled null into `false` BEFORE any hydration hook could derive a
 * fallback, so the host edit page must seed it in its fill data
 * (`ContentEditPage` sets it from the — live or draft — publishing window).
 * The window pickers are hidden while unpublished but stay dehydrated
 * (`dehydratedWhenHidden`), so toggling off actually persists the cleared
 * window instead of silently keeping the record live.
 *
 * `visibility` persists as a Hidden field: the former "Nur Eingeloggt" option
 * never had frontend semantics beyond "invisible outside the preview" (exactly
 * like unpublished), so it left the UI. The column keeps round-tripping — the
 * blueprint default still applies via {@see defaultVisibilityUsing()} and
 * legacy `members` rows are not silently flipped.
 */
class PublishingFields extends FieldKit
{
    protected Closure|string|null $defaultVisibility = null;

    /**
     * Provide the (blueprint-aware) default for the persisted visibility value.
     */
    public function defaultVisibilityUsing(Closure|string $default): static
    {
        $this->defaultVisibility = $default;

        return $this;
    }

    protected function fields(): array
    {
        return [
            'is_published' => Toggle::make('is_published')
                ->label('Veröffentlicht')
                ->dehydrated(false)
                ->default(false)
                ->live()
                ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                    if (! $state) {
                        $set('publish_from', null);
                        $set('publish_until', null);

                        return;
                    }

                    if (blank($get('publish_from'))) {
                        $set('publish_from', now()->format('Y-m-d H:i:s'));
                    }
                }),
            'publish_from' => DateTimePicker::make('publish_from')
                ->label('Veröffentlichen ab')
                ->seconds(false)
                ->live()
                ->visible(fn (Get $get): bool => (bool) $get('is_published'))
                ->dehydratedWhenHidden()
                ->hintAction(self::resetToSavedValueAction('publish_from'))
                // A cleared start date IS "unveröffentlicht" — reflect it on the
                // toggle instead of saving a live-looking form as offline.
                ->afterStateUpdated(fn (?string $state, Set $set) => blank($state) ? $set('is_published', false) : null)
                ->afterContent(
                    Action::make('setPublishFromNow')
                        ->label('Jetzt')
                        ->link()
                        ->size('sm')
                        ->action(fn (Set $set): mixed => $set('publish_from', now()->format('Y-m-d H:i:s'))),
                ),
            'publish_until' => DateTimePicker::make('publish_until')
                ->label('Veröffentlichen bis')
                ->seconds(false)
                ->live()
                ->visible(fn (Get $get): bool => (bool) $get('is_published'))
                ->dehydratedWhenHidden()
                ->hintAction(self::resetToSavedValueAction('publish_until'))
                ->afterContent(
                    Action::make('setPublishUntilNow')
                        ->label('Jetzt')
                        ->link()
                        ->size('sm')
                        ->action(fn (Set $set): mixed => $set('publish_until', now()->format('Y-m-d H:i:s'))),
                ),
            'visibility' => Hidden::make('visibility')
                ->default($this->defaultVisibility ?? ContentVisibility::Public->value),
        ];
    }

    /**
     * A revert-icon hint action (top-right of the field label) for one of the two
     * datetime fields. It only appears while the field is "dirty" — i.e. its current
     * form value differs from the record's saved value — and resets the field back to
     * that saved value, re-syncing the published toggle with the resulting window.
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

                if ($field === 'publish_from') {
                    $set('is_published', filled($original));
                }
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

        return $record instanceof Model
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
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i');
    }

    /**
     * A section-description closure for the section hosting this kit: the given
     * intro followed by one live sentence on what the CURRENT settings mean for
     * visitors. Reads the form state via `Get`, so it re-renders with the
     * (live) toggle and window fields:
     *
     *     Section::make('Sichtbarkeit')
     *         ->description(PublishingFields::sectionDescription('Wann diese Seite …'))
     *         ->schema(PublishingFields::make()->toArray())
     */
    public static function sectionDescription(?string $intro = null): Closure
    {
        return fn (Get $get): string => trim(($intro ?? '').' '.self::effectDescription(
            $get('publish_from'),
            $get('publish_until'),
            $get('visibility'),
        ));
    }

    /**
     * The derived status for an arbitrary form-state publishing window (Carbon,
     * string or null) — used by the header sentence.
     */
    protected static function windowStatus(mixed $publishFrom, mixed $publishUntil): ContentStatus
    {
        return ContentStatus::forWindow(self::parseDateTime($publishFrom), self::parseDateTime($publishUntil));
    }

    /**
     * One plain sentence describing what the CURRENT combination of toggle and
     * window means for visitors — including what will happen automatically and
     * when. Replaces a bare status pill: the effect needs no decoding.
     */
    public static function effectDescription(mixed $publishFrom, mixed $publishUntil, mixed $visibility = null): string
    {
        $visibilityValue = $visibility instanceof ContentVisibility ? $visibility->value : $visibility;

        // Legacy rows only — the option left the UI, but a stored "members"
        // keeps its real effect (hidden outside the preview) and must not be
        // explained away as publicly visible.
        if ($visibilityValue === ContentVisibility::Members->value) {
            return 'Für Besucher nicht sichtbar (Altbestand „Nur Eingeloggt“) — nur über die Vorschau einsehbar.';
        }

        $from = self::parseDateTime($publishFrom);
        $until = self::parseDateTime($publishUntil);

        $at = fn (CarbonInterface $moment): string => $moment->format('d.m.Y \u\m H:i \U\h\r');

        return match (ContentStatus::forWindow($from, $until)) {
            ContentStatus::Draft => 'Für Besucher nicht sichtbar — nur über die Vorschau einsehbar.',
            ContentStatus::Scheduled => $until === null
                ? "Für Besucher noch nicht sichtbar (nur über die Vorschau) — geht am {$at($from)} automatisch online."
                : "Für Besucher noch nicht sichtbar (nur über die Vorschau) — geht am {$at($from)} automatisch online und wird am {$at($until)} wieder ausgeblendet.",
            ContentStatus::Published => $until === null
                ? 'Für Besucher sichtbar.'
                : "Für Besucher sichtbar — wird am {$at($until)} automatisch ausgeblendet.",
            ContentStatus::Expired => "Für Besucher nicht mehr sichtbar — die Veröffentlichung endete am {$at($until)}. Nur über die Vorschau einsehbar.",
        };
    }

    /**
     * Normalize a form-state datetime (Carbon, string, null/blank) to Carbon.
     */
    protected static function parseDateTime(mixed $value): ?Carbon
    {
        return match (true) {
            $value instanceof CarbonInterface => Carbon::instance($value),
            $value === null, $value === '' => null,
            default => Carbon::parse($value),
        };
    }
}
