<?php

namespace Mmoollllee\Cms\Filament\Resources\Redirects\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Mmoollllee\Cms\Filament\Concerns\LocksRecords;
use Mmoollllee\Cms\Filament\Resources\Redirects\RedirectResource;

class EditRedirect extends EditRecord
{
    use LocksRecords;

    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
