<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\RichEditor as ComponentsRichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Tiptap\Marks\LinkPicker;
use Tiptap\Core\Extension;

/**
 * LinkPicker RichEditor Plugin — WordPress-style link picker.
 *
 * Replaces the built-in link tool with a modal offering internal-path
 * autocomplete ({@see ContentPathSuggestions}), a title/tooltip, CSS classes,
 * a rel attribute and wire:navigate. Applies the mark via the editor's
 * setLink/unsetLink commands (like Filament's own LinkAction); the server-side
 * {@see LinkPicker} TipTap mark renders the extra attributes on output.
 *
 * Client side, the `link-attributes` TipTap extension (getTipTapJsExtensions())
 * declares title + wire:navigate on the built-in link mark so both attributes
 * survive editor round-trips; everything else uses the bundled link mark as-is.
 */
class LinkPickerPlugin implements RichContentPlugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [
            app(LinkPicker::class),
        ];
    }

    /**
     * The built-in link mark plus the package's global-attributes extension
     * (title, wire:navigate) — see resources/js/tiptap-extensions/link-attributes.js.
     */
    public function getTipTapJsExtensions(): array
    {
        return [
            FilamentAsset::getScriptSrc('tiptap-link-attributes', 'mmoollllee/filament-cms'),
        ];
    }

    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('linkPicker')
                ->action(arguments: <<<'JS'
                    {
                        href: $getEditor().getAttributes('link')?.href,
                        title: $getEditor().getAttributes('link')?.title,
                        class: $getEditor().getAttributes('link')?.class,
                        rel: $getEditor().getAttributes('link')?.rel,
                        'wire:navigate': $getEditor().getAttributes('link') ? $getEditor().getAttributes('link')['wire:navigate'] : null
                    }
                    JS)
                ->icon(Heroicon::Link),
        ];
    }

    public function getEditorActions(): array
    {
        return [
            Action::make('linkPicker')
                ->modalHeading('Link einfügen')
                ->modalWidth(Width::Medium)
                ->schema(static::linkSchema())
                ->fillForm(function (array $arguments): array {
                    return [
                        'href' => $arguments['href'] ?? null,
                        'title' => $arguments['title'] ?? null,
                        'class' => $arguments['class'] ?? null,
                        'rel' => $arguments['rel'] ?? null,
                        'wire_navigate' => (bool) ($arguments['wire:navigate'] ?? false),
                    ];
                })
                ->action(function (array $arguments, array $data, ComponentsRichEditor $component): void {
                    // A collapsed cursor inside an existing link: widen the selection to
                    // the whole mark first, so editing/removing affects the full link.
                    $isSingleCharacterSelection = ($arguments['editorSelection']['head'] ?? null) === ($arguments['editorSelection']['anchor'] ?? null);

                    $extendMarkRange = $isSingleCharacterSelection
                        ? [EditorCommand::make('extendMarkRange', arguments: ['link'])]
                        : [];

                    if (blank($data['href'] ?? null)) {
                        $component->runCommands(
                            [...$extendMarkRange, EditorCommand::make('unsetLink')],
                            editorSelection: $arguments['editorSelection'],
                        );

                        return;
                    }

                    $component->runCommands(
                        [
                            ...$extendMarkRange,
                            EditorCommand::make('setLink', arguments: [[
                                'href' => $data['href'],
                                'title' => $data['title'] ?: null,
                                'class' => $data['class'] ?: null,
                                'rel' => $data['rel'] ?: null,
                                'wire:navigate' => ($data['wire_navigate'] ?? false) ?: null,
                            ]]),
                        ],
                        editorSelection: $arguments['editorSelection'],
                    );
                }),
        ];
    }

    /**
     * The link form schema: internal-path autocomplete + attribute fields.
     */
    public static function linkSchema(): array
    {
        return [
            ContentPathSuggestions::makeHrefInput()
                ->hiddenLabel(false)
                ->label('URL')
                ->columnSpanFull(),

            TextInput::make('title')
                ->label('Titel (Tooltip)'),

            TextInput::make('class')
                ->label('CSS-Klassen'),

            TextInput::make('rel')
                ->label('rel-Attribut')
                ->placeholder('noopener noreferrer nofollow'),

            Checkbox::make('wire_navigate')
                ->label('wire:navigate (SPA-Navigation)'),
        ];
    }
}
