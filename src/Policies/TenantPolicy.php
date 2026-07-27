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

    /**
     * Gates the tenant settings page (EditTenantProfilePage): every member of the
     * tenant may edit branding, contact data and SEO defaults — Admin *and* Editor.
     * Managing users stays admin-only ({@see UserPolicy}), and everything
     * cross-tenant (listing, creating, deleting tenants) stays superadmin-only.
     */
    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isSuperadmin() || $tenant->hasUser($user);
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
