<?php

namespace Mmoollllee\Cms\Filament\Resources\LayoutPresets\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Mmoollllee\Cms\Filament\Concerns\LocksRecords;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\LayoutPresetResource;

class EditLayoutPreset extends EditRecord
{
    use LocksRecords;

    protected static string $resource = LayoutPresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
