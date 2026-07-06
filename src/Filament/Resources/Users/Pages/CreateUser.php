<?php

namespace Mmoollllee\Cms\Filament\Resources\Users\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $role = $data['role'] ?? TenantUserRole::Editor->value;
        unset($data['role'], $data['password_confirmation']);

        $user = Cms::userModel()::firstOrCreate(
            ['email' => $data['email']],
            $data,
        );

        $tenant = Filament::getTenant();

        if (! $tenant->users()->whereKey($user)->exists()) {
            $tenant->users()->attach($user, ['role' => $role]);
        }

        return $user;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
