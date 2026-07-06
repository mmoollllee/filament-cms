<?php

namespace Mmoollllee\Cms\Policies;

use Filament\Facades\Filament;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantUserRole;

class UserPolicy
{
    protected function currentTenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    protected function isAdminOfCurrentTenant(User $user): bool
    {
        $tenant = $this->currentTenant();

        if ($tenant === null) {
            return false;
        }

        return $user->isSuperadmin() || $user->tenantRole($tenant) === TenantUserRole::Admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdminOfCurrentTenant($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->isAdminOfCurrentTenant($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdminOfCurrentTenant($user);
    }

    public function update(User $user, User $model): bool
    {
        if (! $this->isAdminOfCurrentTenant($user)) {
            return false;
        }

        if ($model->isSuperadmin() && ! $user->isSuperadmin()) {
            return false;
        }

        return true;
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        return $this->update($user, $model);
    }
}
