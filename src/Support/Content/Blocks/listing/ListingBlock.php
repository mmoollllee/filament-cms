<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\listing;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;

class ListingBlock extends BaseBuilderBlock
{
    public function key(): string
    {
        return 'listing';
    }

    public function make(?Tenant $tenant): Block
    {
        // The selectable content types are whatever the tenant's site extensions
        // registered — the engine ships no content taxonomy of its own.
        $contentTypes = app(ContentBlueprintRegistry::class)->options($tenant?->site_key);

        return Block::make('listing')
            ->icon(Heroicon::OutlinedListBullet)
            ->label('Listing')
            ->title('title', placeholder: 'Titel', suffix: 'Listing')
            ->preview('blocks::listing.preview')
            ->schema([
                ...static::optionHiddenFields(),
                Hidden::make('wrapper_preset_ids'),
                Select::make('content_type')
                    ->label('Inhaltstyp')
                    ->options($contentTypes)
                    ->default(array_key_first($contentTypes))
                    ->required(),
                LayoutPreset::selectField('listing-wrapper', $tenant)
                    ->statePath('wrapper_preset_ids')
                    ->label('Wrapper-Layout'),
            ]);
    }
}
