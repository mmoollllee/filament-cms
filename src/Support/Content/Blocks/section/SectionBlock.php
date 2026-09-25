<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\section;

use Filament\Actions\Action;
use Filament\Forms\Components\Builder\Block as BuilderBlock;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Forms\BlockBuilder;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;
use Mmoollllee\Cms\Support\Content\RichText;

class SectionBlock extends BaseBuilderBlock
{
    public function key(): string
    {
        return 'section';
    }

    public function make(?Tenant $tenant): BuilderBlock
    {
        // The header (title via the inline row input, eyebrow, intro text) renders
        // whenever it has content — the header layout (in the block options) only
        // styles it. Most sections never use eyebrow or intro, so the form shows
        // them only on demand: a one-line "Kopfbereich hinzufügen" link instead of
        // an empty editor, header-layout select and eyebrow input in every section.
        return BuilderBlock::make('section')
            ->icon(Heroicon::OutlinedViewColumns)
            ->label('Sektion')
            ->title('title', placeholder: 'Titel', suffix: 'Sektion')
            ->schema([
                ...static::optionHiddenFields(),
                // Edited in the block options — kept here so they survive dehydration.
                Hidden::make('background_image'),
                Hidden::make('header_preset_ids'),
                // Panel-only, never saved: set by the "Kopfbereich hinzufügen" link — and
                // on load for a header that already has content, so clearing its intro
                // to rewrite it does not fold the fields away mid-edit.
                Hidden::make('show_header')
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Hidden $component, Get $get): void {
                        if (static::hasHeaderContent($get)) {
                            $component->state(true);
                        }
                    }),
                // Centered and faded (builder.css) — an offer, not a field to fill.
                Actions::make([
                    Action::make('addSectionHeader')
                        ->label('Kopfbereich hinzufügen')
                        ->tooltip('Eyebrow und Intro-Text über den Blöcken der Sektion')
                        ->icon(Heroicon::OutlinedPlus)
                        ->link()
                        ->color('gray')
                        ->size(Size::Small)
                        ->action(fn (Set $set) => $set('show_header', true)),
                ])
                    ->alignCenter()
                    ->extraAttributes(['class' => 'fi-cms-section-header-toggle'])
                    ->hidden(fn (Get $get): bool => static::showsHeader($get)),
                // Hidden while empty, but saved either way: whatever the visibility
                // heuristic concludes, it may only ever cost a click, never content.
                Group::make([
                    TextInput::make('eyebrow')
                        ->label('Eyebrow')
                        ->maxLength(100),
                    static::richEditorWithSource(),
                ])
                    ->visible(fn (Get $get): bool => static::showsHeader($get))
                    ->dehydratedWhenHidden(),
                BlockBuilder::make('blocks', $tenant, $this->childBlocks($tenant), sortableGroup: 'section-blocks'),
            ]);
    }

    /**
     * Whether the section's eyebrow + intro fields are on screen: once they hold
     * anything, or after the editor asked for them.
     */
    protected static function showsHeader(Get $get): bool
    {
        return (bool) $get('show_header') || static::hasHeaderContent($get);
    }

    protected static function hasHeaderContent(Get $get): bool
    {
        return filled($get('eyebrow')) || ! RichText::isBlank($get('content'));
    }

    /**
     * @return array<int, BuilderBlock>
     */
    protected function childBlocks(?Tenant $tenant): array
    {
        $blocks = app(BuilderBlockRegistry::class)->except(['section'], $tenant);

        // Per-project restriction: a site_key may limit which child blocks a section
        // allows. Register via Cms::allowSectionChildren('siteKey', [keys…]).
        $allowed = Cms::sectionChildAllowlist($tenant?->site_key);

        if ($allowed === null) {
            return $blocks;
        }

        return array_values(array_filter(
            $blocks,
            fn (BuilderBlock $block): bool => in_array($block->getName(), $allowed, true),
        ));
    }
}
