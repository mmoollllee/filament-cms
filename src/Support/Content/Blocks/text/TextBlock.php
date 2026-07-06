<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\text;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;

class TextBlock extends BaseBuilderBlock
{
    public function key(): string
    {
        return 'text';
    }

    public function make(?Tenant $tenant): Block
    {
        return Block::make('text')
            ->icon(Heroicon::OutlinedDocumentText)
            ->label('Text')
            ->title('title', placeholder: 'Titel', suffix: 'Text')
            ->preview('blocks::text.preview')
            ->schema([
                ...static::optionHiddenFields(),
                TextInput::make('eyebrow')
                    ->label('Eyebrow')
                    ->maxLength(100),
                static::richEditorWithSource(),
            ]);
    }
}
