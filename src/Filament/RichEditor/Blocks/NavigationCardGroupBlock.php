<?php

namespace Mmoollllee\Cms\Filament\RichEditor\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Mmoollllee\Cms\Filament\RichEditor\ContentPathSuggestions;

/**
 * Block-level navigation card group for the RichEditor.
 *
 * Renders mini-cards with label, description text, and arrow icon — e.g. as a
 * teaser row linking to sub-pages from a hero or intro section.
 *
 * @see ButtonGroupBlock  For CTA button groups
 * @see \Mmoollllee\Cms\Filament\RichEditor\LinkPickerPlugin  For inline button-links
 */
class NavigationCardGroupBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'navigationCardGroup';
    }

    public static function getLabel(): string
    {
        return 'Navigations-Karten';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Navigations-Karten bearbeiten')
            ->schema([
                Repeater::make('cards')
                    ->label('Karten')
                    ->schema([
                        ContentPathSuggestions::makeLabelInput('label', 'href'),
                        ContentPathSuggestions::makeHrefInputWithLabel('href', 'label'),
                        Checkbox::make('wire_navigate')
                            ->label('wire:navigate')
                            ->columnSpan(4),

                        Textarea::make('text')
                            ->label('Beschreibungstext (optional)')
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('rel')
                            ->label('rel-Attribut')
                            ->placeholder('noopener noreferrer')
                            ->columnSpan(6),
                    ])
                    ->itemLabel(fn (?array $state): string => $state['label'] ?? 'Karte')
                    ->hiddenLabel()
                    ->columns(12)
                    ->minItems(1)
                    ->collapsed()
                    ->collapseAllAction(fn (Action $action) => $action->hidden())
                    ->expandAllAction(fn (Action $action) => $action->hidden())
                    ->addActionLabel('Karte hinzufügen')
                    ->defaultItems(1),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
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
        $cards = $config['cards'] ?? [];

        if (empty($cards)) {
            return null;
        }

        return view('components.site.rich-editor.nav-cards', [
            'cards' => $cards,
            'preview' => $preview,
        ])->render();
    }
}
