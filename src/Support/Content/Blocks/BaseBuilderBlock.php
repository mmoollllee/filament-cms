<?php

namespace Mmoollllee\Cms\Support\Content\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Forms\MediaField;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\ButtonGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\NavigationCardGroupBlock;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Support\Content\Blocks\Contracts\BuilderBlock;
use Mmoollllee\Cms\Support\Content\RichText;
use Mmoollllee\FilamentBuilderExtensions\Clipboard\CopyItemAction;
use Mmoollllee\FilamentBuilderExtensions\Clipboard\PasteItemAction;

abstract class BaseBuilderBlock implements BuilderBlock
{
    /** What a copied block is to the clipboard: only a paste of this kind takes it. */
    public const CLIPBOARD_KIND = 'cms.block';

    /** The name {@see pasteBlockAction()} registers under, by which the block picker finds it. */
    public const PASTE_ACTION = 'pasteBlock';

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
     *
     * The tab switch renders as a small icon control in the editor's top-right
     * corner (`fi-cms-editor-tabs` in builder.css) rather than a row of its own. It
     * stays a real Tabs component: both fields must remain in the form, since a
     * hidden field is not saved and the HTML tab holds the persisted value.
     */
    protected static function richEditorWithSource(string $name = 'content'): Tabs
    {
        $editorKey = "_{$name}_editor";

        return Tabs::make('editor')
            ->contained(false)
            ->extraAttributes(['class' => 'fi-cms-editor-tabs'])
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
                Tab::make('HTML-Quelltext')
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
     * (active toggle, layout preset, heading level, anchor ID).
     *
     * Scope-aware per item: a `section` item gets `section`-scoped presets plus the
     * header layout and the background-image upload (and any $sectionExtraSchema);
     * every other block type gets `section-child`-scoped presets. One action serves
     * mixed builders (e.g. a fragment holding sections AND plain blocks at its root).
     *
     * Ordered by use: the layout preset first (set on nearly every block), the
     * rarely touched anchor and background image last.
     *
     * @param  array<int, Component>  $sectionExtraSchema  extra option fields shown for section items only
     */
    public static function blockOptionsAction(
        ?Tenant $tenant = null,
        array $sectionExtraSchema = [],
    ): Action {
        $sectionSchema = fn (): array => [
            static::sectionBackgroundImageField($tenant)
                ->columnSpanFull(),
            ...$sectionExtraSchema,
        ];

        $isSection = fn (array $arguments, Builder $component): bool => ($component->getRawState()[$arguments['item']]['type'] ?? null) === 'section';

        $optionKeys = function (array $arguments, Builder $component) use ($isSection, $sectionSchema): array {
            $sectionKeys = $isSection($arguments, $component)
                ? ['header_preset_ids', ...collect($sectionSchema())->map(fn ($field) => $field->getName())->all()]
                : [];

            return [...$sectionKeys, 'active', 'layout_preset_ids', 'anchor_id', 'heading'];
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
                        ...($isSection($arguments, $component) ? [static::sectionHeaderPresetField($tenant)] : []),
                        TextInput::make('anchor_id')
                            ->label('Anker-ID (für #-Links)')
                            ->maxLength(255),
                        ...($isSection($arguments, $component) ? $sectionSchema() : []),
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
     * Extra item action that copies the block (type + current data) to the clipboard,
     * localStorage for a day where the browser refuses it — the package's CopyItemAction
     * (mmoollllee/filament-builder-extensions), read in the browser so unsaved edits are
     * included. {@see pasteBlockAction()} takes it back, across pages, tenants and tabs.
     */
    public static function copyBlockAction(): Action
    {
        return CopyItemAction::make('copyBlock')
            ->kind(static::CLIPBOARD_KIND)
            ->label('Block kopieren')
            ->successNotificationTitle('Block kopiert');
    }

    /**
     * Pastes a block copied with {@see copyBlockAction()}, registered on every builder and
     * offered by its block picker ("Aus Zwischenablage"): last from the picker below the
     * blocks, after the block above from the picker between two. Only a block the builder
     * offers is taken, and only what fits its fields; the rest gets the fields' defaults.
     */
    public static function pasteBlockAction(): PasteItemAction
    {
        return PasteItemAction::make(static::PASTE_ACTION)
            ->kind(static::CLIPBOARD_KIND)
            // Short enough for the picker's width; the tooltip says it in full.
            ->label('Aus Zwischenablage')
            ->tooltip('Aus Zwischenablage einfügen')
            ->successNotificationTitle('Block eingefügt')
            ->failureNotificationTitle('Kein passender Block in der Zwischenablage');
    }

    /**
     * The header-layout picker in the block options of section items: it styles
     * the section header (width, alignment) and is set once, if at all — so it
     * lives beside the other layout options instead of in every section's form.
     *
     * Named explicitly: selectField() names its field `layout_preset_ids`, which
     * would collide with the section's own layout field in the same dialog.
     */
    protected static function sectionHeaderPresetField(?Tenant $tenant): Select
    {
        return LayoutPreset::selectField('section-header', $tenant)
            ->name('header_preset_ids')
            ->statePath('header_preset_ids')
            ->label('Header-Layout');
    }

    /**
     * The background-image field offered in the block options of section items
     * (media-library picker when active, classic upload otherwise).
     */
    protected static function sectionBackgroundImageField(?Tenant $tenant): Field
    {
        return MediaField::image('background_image', legacyDirectory: static::uploadDirectory($tenant))
            ->label('Hintergrundbild');
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
            // The active state drives the builder override's "inaktiv" pill, which
            // also dims the row (builder.css); this Hidden field only persists the
            // value through dehydration.
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
