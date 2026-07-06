<?php

namespace Mmoollllee\Cms\Fields;

use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
            'status' => Select::make('status')
                ->label('Status')
                ->options(ContentStatus::options())
                ->default(fn ($record): string => $record?->status()->value ?? ContentStatus::Draft->value)
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (string $state, Set $set): void {
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
                            $set('publish_until', now()->subDay()->endOfDay()->format('Y-m-d H:i:s'));
                        })(),
                    };
                }),
            'publish_from' => DateTimePicker::make('publish_from')
                ->label('Veröffentlichen ab')
                ->live()
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
                ->live()
                ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => $set('status', self::computeStatus($get('publish_from'), $state)))
                ->afterContent(
                    Action::make('setPublishUntilNow')
                        ->label('Jetzt')
                        ->link()
                        ->size('sm')
                        ->action(fn (Set $set, Get $get): mixed => $set('publish_until', now()->format('Y-m-d H:i:s')) ?? $set('status', self::computeStatus($get('publish_from'), now()->format('Y-m-d H:i:s')))),
                ),
            'visibility' => Select::make('visibility')
                ->required()
                ->options(ContentVisibility::options())
                ->default($this->defaultVisibility ?? ContentVisibility::Public->value)
                ->columnSpanFull(),
        ];
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
