<?php

namespace Mmoollllee\Cms\Support\Content\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Js;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\ButtonGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\NavigationCardGroupBlock;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Support\Content\Blocks\Contracts\BuilderBlock;
use Mmoollllee\Cms\Support\Content\RichText;

abstract class BaseBuilderBlock implements BuilderBlock
{
    protected static function layoutPresetField(string $type, ?Tenant $tenant = null): Select
    {
        return LayoutPreset::selectField($type, $tenant);
    }

    /**
     * RichEditor with an HTML source tab for raw editing.
     *
     * Uses two state keys because TipTap internally stores JSON, not HTML:
     * - `$name` (e.g. 'content'): the persisted HTML field shown in the HTML tab
     * - `_{$name}_editor`: virtual RichEditor field (dehydrated: false)
     *
     * HtmlPreservePlugin (registered globally) ensures custom div/span HTML
     * survives the HTML→JSON→HTML roundtrip inside TipTap.
     */
    protected static function richEditorWithSource(string $name = 'content'): Tabs
    {
        $editorKey = "_{$name}_editor";

        return Tabs::make('editor')
            ->contained(false)
            ->tabs([
                Tab::make('Editor')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        RichEditor::make($editorKey)
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->customBlocks([
                                ButtonGroupBlock::class,
                                NavigationCardGroupBlock::class,
                            ])
                            // Stored TipTap JSON (e.g. seeded custom-block content) is
                            // converted to editor HTML — the editor requires a string.
                            ->formatStateUsing(fn (callable $get): ?string => RichText::editorHtml($get($name)))
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set($name, $state))
                            ->live(onBlur: true),
                    ]),
                Tab::make('HTML')
                    ->icon(Heroicon::OutlinedCodeBracket)
                    ->schema([
                        CodeEditor::make($name)
                            ->hiddenLabel()
                            // Same conversion: CodeMirror crashes on non-string state
                            // ("(e.doc||'').split is not a function").
                            ->formatStateUsing(fn (callable $get): ?string => RichText::editorHtml($get($name)))
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set($editorKey, $state))
                            ->live(onBlur: true),
                    ]),
            ])
            ->columnSpanFull();
    }

    /**
     * Extra item action that opens a modal with common block options
     * (active toggle, layout preset, anchor ID, heading level).
     *
     * Scope-aware per item: a `section` item gets `section`-scoped presets plus the
     * background-image upload (and any $sectionExtraSchema); every other block type
     * gets `section-child`-scoped presets. One action serves mixed builders (e.g. a
     * fragment holding sections AND plain blocks at its root).
     *
     * @param  array<int, Component>  $sectionExtraSchema  extra option fields shown for section items only
     */
    public static function blockOptionsAction(
        ?Tenant $tenant = null,
        array $sectionExtraSchema = [],
    ): Action {
        $sectionSchema = fn (): array => [
            static::sectionBackgroundImageField($tenant),
            ...$sectionExtraSchema,
        ];

        $isSection = fn (array $arguments, Builder $component): bool => ($component->getRawState()[$arguments['item']]['type'] ?? null) === 'section';

        $optionKeys = function (array $arguments, Builder $component) use ($isSection, $sectionSchema): array {
            $extraKeys = $isSection($arguments, $component)
                ? collect($sectionSchema())->map(fn ($field) => $field->getName())->all()
                : [];

            return [...$extraKeys, 'active', 'layout_preset_ids', 'anchor_id', 'heading'];
        };

        return Action::make('blockOptions')
            ->label('Block-Optionen')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->schema(fn (array $arguments, Builder $component): array => [
                Grid::make(2)
                    ->schema([
                        Toggle::make('active')
                            ->label('Aktiv')
                            ->default(true)
                            ->columnSpanFull(),
                        ...($isSection($arguments, $component) ? $sectionSchema() : []),
                        static::layoutPresetField($isSection($arguments, $component) ? 'section' : 'section-child', $tenant)
                            ->columnSpanFull(),
                        Select::make('heading')
                            ->label('Überschrift')
                            ->options([
                                null => 'keine',
                                'h1' => 'H1',
                                'h2' => 'H2',
                                'h3' => 'H3',
                            ])
                            ->selectablePlaceholder(false)
                            ->default('h2'),
                        TextInput::make('anchor_id')
                            ->label('Anker-ID (für #-Links)')
                            ->maxLength(255),
                    ]),
            ])
            ->fillForm(function (array $arguments, Builder $component) use ($optionKeys): array {
                $itemData = $component->getRawItemState($arguments['item']);

                return collect($optionKeys($arguments, $component))
                    ->mapWithKeys(fn (string $key): array => [
                        $key => $key === 'active'
                            ? ($itemData[$key] ?? true)
                            : ($itemData[$key] ?? null),
                    ])
                    ->all();
            })
            ->action(function (array $data, array $arguments, Builder $component) use ($optionKeys): void {
                $state = $component->getState();
                $uuid = $arguments['item'];

                foreach ($optionKeys($arguments, $component) as $key) {
                    $state[$uuid]['data'][$key] = $data[$key] ?? null;
                }

                $component->state($state);
            });
    }

    /**
     * Extra item action that copies the block (type + current data) as JSON to the
     * clipboard — the counterpart of the block picker's "Aus Zwischenablage einfügen"
     * ({@see \Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks}). Runs entirely
     * client-side (Alpine), reading the live item state via $wire so unsaved edits
     * are included; localStorage is the fallback for clipboard-restricted browsers.
     */
    public static function copyBlockAction(): Action
    {
        return Action::make('copyBlock')
            ->label('Block kopieren')
            ->icon(Heroicon::OutlinedClipboardDocument)
            ->alpineClickHandler(function (array $arguments, Builder $component): string {
                $itemPath = Js::from("{$component->getStatePath()}.{$arguments['item']}")->toHtml();

                return <<<JS
                    const payload = JSON.stringify(\$wire.get({$itemPath}));
                    try { await navigator.clipboard.writeText(payload); } catch (e) {}
                    localStorage.setItem('filament_builder_clipboard', payload);
                    new FilamentNotification().title('Block kopiert').success().send();
                    JS;
            });
    }

    /**
     * The background-image upload offered in the block options of section items.
     */
    protected static function sectionBackgroundImageField(?Tenant $tenant): FileUpload
    {
        return FileUpload::make('background_image')
            ->label('Hintergrundbild')
            ->disk('public')
            ->visibility('public')
            ->directory(static::uploadDirectory($tenant))
            ->image()
            ->imagePreviewHeight('120');
    }

    /**
     * Hidden fields that preserve block-option values during dehydration.
     *
     * Every block schema must spread these so the option keys survive save.
     *
     * @return array<int, Hidden>
     */
    protected static function optionHiddenFields(): array
    {
        return [
            // The active state drives the row dimming via the builder override's
            // per-item `fi-fo-builder-item-inactive` class; this Hidden field only
            // persists the value through dehydration.
            Hidden::make('active')
                ->default(true),
            Hidden::make('title'),
            Hidden::make('layout_preset_ids'),
            Hidden::make('anchor_id'),
            Hidden::make('heading')->default('h2'),
        ];
    }

    public static function uploadDirectory(?Tenant $tenant): string
    {
        $siteKey = $tenant?->site_key ?: 'default';

        return "tenants/{$siteKey}/content-blocks";
    }
}
