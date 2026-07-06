<?php

namespace Mmoollllee\Cms\Policies;

use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->isSuperadmin();
    }

    public function restore(User $user, Tenant $tenant): bool
    {
        return $user->isSuperadmin();
    }

    public function forceDelete(User $user, Tenant $tenant): bool
    {
        return $user->isSuperadmin();
    }
}
