<?php

namespace Mmoollllee\Cms\Filament\Resources\Users\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['role'] = $this->getRecord()->tenantRole($tenant)?->value;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $role = $data['role'] ?? null;
        unset($data['role'], $data['password_confirmation']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $record->update($data);

        if ($role !== null) {
            Filament::getTenant()->users()->updateExistingPivot($record->id, ['role' => $role]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
