<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\section;

use Filament\Forms\Components\Builder\Block as BuilderBlock;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Forms\BlockBuilder;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;

class SectionBlock extends BaseBuilderBlock
{
    public function key(): string
    {
        return 'section';
    }

    public function make(?Tenant $tenant): BuilderBlock
    {
        // The header (title via the inline row input, intro text, eyebrow) renders
        // whenever it has content — the header preset only styles it. No gating,
        // no Fieldset wrapper, no Hidden duplicates for gated fields.
        return BuilderBlock::make('section')
            ->icon(Heroicon::OutlinedViewColumns)
            ->label('Sektion')
            ->title('title', placeholder: 'Titel', suffix: 'Sektion')
            ->schema([
                ...static::optionHiddenFields(),
                Hidden::make('background_image'),
                static::richEditorWithSource(),
                Grid::make(2)
                    ->schema([
                        LayoutPreset::selectField('section-header', $tenant)
                            ->statePath('header_preset_ids')
                            ->label('Header-Layout'),
                        TextInput::make('eyebrow')
                            ->label('Eyebrow')
                            ->maxLength(100),
                    ]),
                BlockBuilder::make('blocks', $tenant, $this->childBlocks($tenant), sortableGroup: 'section-blocks'),
            ]);
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
