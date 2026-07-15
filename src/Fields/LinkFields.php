<?php

namespace Mmoollllee\Cms\Fields;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Mmoollllee\Cms\Filament\Forms\ContentPathSuggestions;

/**
 * Link field group for resource forms — same options as the RichEditor's
 * link-picker modal (keep the two in sync: {@see \Mmoollllee\Cms\Filament\RichEditor\LinkPickerPlugin::linkSchema()}):
 * URL with internal-path autocomplete, button label (auto-filled with the
 * page title on suggestion select), wire:navigate, and a collapsed
 * "Erweitert" section for tooltip title, CSS classes and rel.
 *
 * Field names derive from the base state path:
 *
 *     LinkFields::make('payload.link')->toArray()
 *     // payload.link, payload.link_label, payload.link_wire_navigate,
 *     // payload.link_title, payload.link_class, payload.link_rel
 *
 * Render the stored values with {@see \Mmoollllee\Cms\Support\Content\PayloadLink}.
 */
class LinkFields extends FieldKit
{
    protected string $base = 'link';

    public static function make(string $base = 'link'): static
    {
        $kit = parent::make();
        $kit->base = $base;

        return $kit;
    }

    /**
     * Compact by design: URL and Beschriftung pair up side by side in a
     * multi-column host grid (give the surrounding section `->columns(2)`);
     * in a single-column host they stack. Checkbox and the compact
     * "Erweitert" section always take the full row.
     */
    protected function fields(): array
    {
        $base = $this->base;

        return [
            'url' => ContentPathSuggestions::makeHrefInputWithLabel($base, "{$base}_label")
                ->label('Link'),

            'label' => TextInput::make("{$base}_label")
                ->label('Button-Beschriftung')
                ->placeholder('Mehr erfahren'),

            'wire_navigate' => Checkbox::make("{$base}_wire_navigate")
                ->label('wire:navigate (SPA-Navigation ohne Neuladen)')
                ->columnSpanFull(),

            'advanced' => Section::make('Erweitert')
                ->schema([
                    TextInput::make("{$base}_title")
                        ->label('Titel (Tooltip)'),

                    TextInput::make("{$base}_class")
                        ->label('CSS-Klassen'),

                    TextInput::make("{$base}_rel")
                        ->label('rel-Attribut')
                        ->placeholder('noopener noreferrer nofollow'),
                ])
                ->columns(3)
                ->compact()
                ->collapsible()
                ->collapsed(fn (Get $get): bool => blank($get("{$base}_title")) && blank($get("{$base}_class")) && blank($get("{$base}_rel")))
                ->columnSpanFull(),
        ];
    }
}
