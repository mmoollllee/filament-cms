<?php

namespace Mmoollllee\Cms\Filament\Resources\LayoutPresets\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\LayoutPresetResource;

class ListLayoutPresets extends ListRecords
{
    protected static string $resource = LayoutPresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
