<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\RichEditor as ComponentsRichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Filament\Forms\ContentPathSuggestions;
use Mmoollllee\Cms\Tiptap\Marks\LinkPicker;
use Tiptap\Core\Extension;

/**
 * LinkPicker RichEditor Plugin — WordPress-style link picker.
 *
 * Replaces the built-in link tool with a modal offering internal-path
 * autocomplete ({@see ContentPathSuggestions}) and wire:navigate up front,
 * plus a collapsed "Erweitert" section (title/tooltip, CSS classes, rel).
 * Applies the mark via the editor's
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
     * The built-in link mark plus the package's extensions: link-attributes
     * (title, wire:navigate survive re-editing) and link-bubble (floating
     * "Bearbeiten"/"Entfernen" tooltip on an existing link) — sources in
     * resources/js/tiptap-extensions/.
     */
    public function getTipTapJsExtensions(): array
    {
        return [
            FilamentAsset::getScriptSrc('tiptap-link-attributes', 'mmoollllee/filament-cms'),
            FilamentAsset::getScriptSrc('tiptap-link-bubble', 'mmoollllee/filament-cms'),
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
                ->icon(Heroicon::Link)
                // Stable hook for the link-bubble's "Bearbeiten" button — it
                // re-triggers this tool so the modal mounts with the live
                // editorSelection + prefilled attributes.
                ->extraAttributes(['data-cms-tool' => 'link-picker']),
        ];
    }

    public function getEditorActions(): array
    {
        return [
            Action::make('linkPicker')
                ->modalHeading(fn (array $arguments): string => filled($arguments['href'] ?? null) ? 'Link bearbeiten' : 'Link einfügen')
                ->modalSubmitActionLabel(fn (array $arguments): string => filled($arguments['href'] ?? null) ? 'Speichern' : 'Einfügen')
                ->modalWidth(Width::Large)
                ->schema(fn (array $arguments): array => static::linkSchema(editing: filled($arguments['href'] ?? null)))
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
     * The link form schema: internal-path autocomplete up front, the rarely
     * used attribute fields tucked into a collapsed "Erweitert" section (it
     * opens expanded when the edited link already carries such attributes).
     *
     * The URL is deliberately NOT required: clearing it and saving removes the
     * link (the action's unsetLink branch) — same UX as Filament's built-in
     * link tool.
     */
    public static function linkSchema(bool $editing = false): array
    {
        return [
            ContentPathSuggestions::makeHrefInput()
                ->autofocus()
                ->helperText($editing ? 'Leer lassen und speichern, um den Link zu entfernen.' : null)
                ->columnSpanFull(),

            Checkbox::make('wire_navigate')
                ->label('wire:navigate (SPA-Navigation ohne Neuladen)'),

            Section::make('Erweitert')
                ->schema([
                    TextInput::make('title')
                        ->label('Titel (Tooltip)')
                        ->columnSpanFull(),

                    TextInput::make('class')
                        ->label('CSS-Klassen'),

                    TextInput::make('rel')
                        ->label('rel-Attribut')
                        ->placeholder('noopener noreferrer nofollow'),
                ])
                ->columns(2)
                ->compact()
                ->collapsible()
                ->collapsed(fn (Get $get): bool => blank($get('title')) && blank($get('class')) && blank($get('rel'))),
        ];
    }
}
