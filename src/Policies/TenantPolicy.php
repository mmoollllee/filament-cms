<?php

namespace Mmoollllee\Cms\Policies;

use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantUserRole;

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

    /**
     * Send, resend and withdraw invitations — the filament-tenant-access
     * access list asks this. Same rule as managing users ({@see UserPolicy}):
     * an admin of this site.
     */
    public function inviteMembers(User $user, Tenant $tenant): bool
    {
        return $this->isAdminOf($user, $tenant);
    }

    /**
     * Change a member's role or end their membership. Same rule as inviting;
     * the row-level exceptions (not yourself, not a superadmin) are
     * {@see UserPolicy::detach()}, which the users list adds per row.
     */
    public function manageMembers(User $user, Tenant $tenant): bool
    {
        return $this->isAdminOf($user, $tenant);
    }

    /**
     * Attach EXISTING accounts without a mail. Superadmin only: it presupposes a
     * picker over the entire user directory, which a single site's admin has no
     * business seeing.
     */
    public function assignMembers(User $user, Tenant $tenant): bool
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

    protected function isAdminOf(User $user, Tenant $tenant): bool
    {
        return $user->isSuperadmin() || $user->tenantRole($tenant) === TenantUserRole::Admin;
    }
}
