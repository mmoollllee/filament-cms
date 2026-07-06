<?php

namespace Workbench\App\Support\Content\Blocks\hint;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;

/**
 * The demo's own builder block — a colored hint/callout box.
 *
 * This is the complete "custom block" recipe the /howto/custom-blocks page
 * teaches: one class (key + Filament Block), two views
 * (workbench/resources/blocks/hint/{hint,preview}.blade.php) and one line in
 * Cms::registerBlocks() (WorkbenchServiceProvider). Nothing else.
 */
class HintBlock extends BaseBuilderBlock
{
    public function key(): string
    {
        return 'hint';
    }

    public function make(?Tenant $tenant): Block
    {
        return Block::make('hint')
            ->icon(Heroicon::OutlinedLightBulb)
            ->label('Hinweis')
            ->title('title', placeholder: 'Titel', suffix: 'Hinweis')
            ->preview('blocks::hint.preview')
            ->schema([
                ...static::optionHiddenFields(),
                Select::make('tone')
                    ->label('Ton')
                    ->options([
                        'info' => 'Info',
                        'success' => 'Erfolg',
                        'warning' => 'Warnung',
                    ])
                    ->default('info')
                    ->selectablePlaceholder(false),
                static::richEditorWithSource(),
            ]);
    }
}
