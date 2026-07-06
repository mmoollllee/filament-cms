<?php

namespace Mmoollllee\Cms\Filament\Resources\Fragments\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;

class ListFragments extends ListRecords
{
    protected static string $resource = FragmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
