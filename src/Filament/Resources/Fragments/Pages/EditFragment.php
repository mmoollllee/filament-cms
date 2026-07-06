<?php

namespace Mmoollllee\Cms\Filament\Resources\Fragments\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;

class EditFragment extends EditRecord
{
    use PastesBuilderBlocks;

    protected static string $resource = FragmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
