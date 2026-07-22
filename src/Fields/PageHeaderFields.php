<?php

namespace Mmoollllee\Cms\Fields;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Mmoollllee\Cms\Filament\Forms\MediaField;

/**
 * Page-header fields (heading, subheading, header image, card thumbnail) stored
 * under `payload.hero.*`. Opt-in: a resource includes it only when it actually
 * renders a page header (e.g. via the RendersPageHeader trait), so the field
 * set stays a per-project choice rather than a baked-in default.
 *
 */
class PageHeaderFields extends FieldKit
{
    protected ?string $uploadDirectory = null;

    public function uploadDirectory(?string $directory): static
    {
        $this->uploadDirectory = $directory;

        return $this;
    }

    protected function fields(): array
    {
        $isLarge = fn (Get $get): bool => $get('payload.hero.size') === 'gross';

        return [
            'size' => Select::make('payload.hero.size')
                ->label('Darstellung')
                ->options(['kompakt' => 'Kompakt', 'gross' => 'Groß (Hero)'])
                ->default('kompakt')
                ->selectablePlaceholder(false)
                ->native(false)
                ->live(),
            'title' => TextInput::make('payload.hero.title')
                ->label('Titel')
                ->helperText('Ausfüllen, um abweichenden Titel im Header zu zeigen.')
                // Live placeholder mirrors the page title (the header falls back to it when empty).
                ->placeholder(fn (Get $get): ?string => $get('title'))
                ->maxLength(255),
            'subtitle' => Textarea::make('payload.hero.subtitle')
                ->label('Untertitel')
                ->rows(3)
                ->columnSpanFull(),
            'thumbnail' => $this->upload('payload.hero.thumbnail', 'Kachelbild', 'Vorschaubild für Übersichtsseiten.'),
            'image' => $this->upload('payload.hero.image', 'Titelbild', 'Hintergrund im Seitenkopf. Wenn Leer wird Kachelbild verwendet.'),
            'cta_label' => TextInput::make('payload.hero.cta_label')
                ->label('Button-Text')
                ->maxLength(100)
                ->visible($isLarge),
            'cta_url' => TextInput::make('payload.hero.cta_url')
                ->label('Button-Link')
                ->maxLength(255)
                ->visible($isLarge),
            'float_image' => $this->upload('payload.hero.float_image', 'Schwebendes Bild', 'Foto, das über die Hero-Kante ragt (nur „Groß").')
                ->visible($isLarge),
        ];
    }

    private function upload(string $key, string $label, string $helperText): Field
    {
        return MediaField::image($key, legacyDirectory: $this->uploadDirectory, imagePreviewHeight: '80')
            ->label($label)
            ->helperText($helperText);
    }
}
