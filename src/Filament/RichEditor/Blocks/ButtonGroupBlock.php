<?php

namespace Mmoollllee\Cms\Filament\RichEditor\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Mmoollllee\Cms\Filament\Forms\ContentPathSuggestions;
use Mmoollllee\Cms\Filament\RichEditor\IconOptions;

/**
 * Block-level button group for the RichEditor.
 *
 * Allows editors to insert a group of styled buttons (CTAs) as a block-level
 * element within rich text content. Each button can have its own variant,
 * size, URL, and optional wire:navigate attribute.
 *
 * @see \Mmoollllee\Cms\Filament\RichEditor\LinkPickerPlugin  For inline button-links
 */
class ButtonGroupBlock extends RichContentCustomBlock
{
    /** Button variant options matching the button CSS system. */
    public const BUTTON_VARIANTS = [
        'primary' => 'Primary (Gradient)',
        'secondary' => 'Secondary (Outlined)',
        'surface' => 'Surface (Weiß + Shadow)',
        'soft' => 'Soft (Hellgrau)',
        'dark' => 'Dark (Schwarz)',
        'light' => 'Light (Weiß)',
        'ghost-light' => 'Ghost Light (Transparent)',
    ];

    public const BUTTON_SIZES = [
        'sm' => 'Klein',
        'md' => 'Mittel',
        'lg' => 'Groß',
    ];

    public static function getId(): string
    {
        return 'buttonGroup';
    }

    public static function getLabel(): string
    {
        return 'Buttons';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Button-Gruppe bearbeiten')
            ->schema([
                Repeater::make('buttons')
                    ->schema([
                        ContentPathSuggestions::makeLabelInput('label', 'href')
                            ->hiddenLabel()
                            ->required()
                            ->live()
                            ->columnSpan(4),
                        ContentPathSuggestions::makeHrefInputWithLabel('href', 'label')
                            ->hiddenLabel()
                            ->required()
                            ->columnSpan(4),
                        Checkbox::make('wire_navigate')
                            ->columnSpan(4)
                            ->label('wire:navigate'),

                        TextInput::make('rel')
                            ->label('rel-Attribut')
                            ->placeholder('noopener noreferrer')
                            ->columnSpan(6),

                        Select::make('variant')
                            ->label('Stil')
                            ->options(static::BUTTON_VARIANTS)
                            ->default('primary')
                            ->columnSpan(3),
                        Select::make('size')
                            ->label('Größe')
                            ->options(static::BUTTON_SIZES)
                            ->default('md')
                            ->columnSpan(3),
                        Select::make('icon')
                            ->label('Icon')
                            ->placeholder('Kein Icon')
                            ->options(IconOptions::options())
                            ->live()
                            ->columnSpan(3),
                        Select::make('icon_position')
                            ->label('Icon-Position')
                            ->options([
                                'after' => 'Nach dem Text',
                                'before' => 'Vor dem Text',
                            ])
                            ->default('after')
                            ->visible(fn (Get $get): bool => filled($get('icon')))
                            ->columnSpan(3),
                    ])
                    ->itemLabel(fn (?array $state): string => $state['label'] ?? 'Button')
                    ->hiddenLabel()
                    ->columns(12)
                    ->minItems(1)
                    ->collapsed()
                    ->collapseAllAction(fn (Action $action) => $action->hidden())
                    ->expandAllAction(fn (Action $action) => $action->hidden())
                    ->addActionLabel('Button hinzufügen')
                    ->defaultItems(1),
                Select::make('alignment')
                    ->label('Ausrichtung')
                    ->options([
                        'start' => 'Links',
                        'center' => 'Zentriert',
                        'end' => 'Rechts',
                    ])
                    ->default('start'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config  Block configuration from the editor
     * @param  array<string, mixed>  $data  Additional data (unused)
     */
    public static function toHtml(array $config, array $data): ?string
    {
        return static::renderView($config, preview: false);
    }

    /** @param  array<string, mixed>  $config */
    public static function toPreviewHtml(array $config): ?string
    {
        return static::renderView($config, preview: true);
    }

    /** @param  array<string, mixed>  $config */
    protected static function renderView(array $config, bool $preview): ?string
    {
        $buttons = $config['buttons'] ?? [];

        if (empty($buttons)) {
            return null;
        }

        return view('components.site.rich-editor.button-group', [
            'buttons' => $buttons,
            'alignment' => $config['alignment'] ?? 'start',
            'preview' => $preview,
        ])->render();
    }
}
