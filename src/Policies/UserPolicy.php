<?php

namespace Mmoollllee\Cms\Policies;

use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Policies\Concerns\AuthorizesTenantAdmins;

class UserPolicy
{
    use AuthorizesTenantAdmins;

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

    /**
     * Revoke a member's access to the tenant currently being administered —
     * the tenant-scoped counterpart of delete(), and the one a tenant admin
     * gets. It unlinks a membership; the account itself survives, along with
     * every other tenant it belongs to.
     */
    public function detach(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        return $this->update($user, $model);
    }

    /**
     * Delete the account itself. Superadmin-only on purpose: the user resource
     * is scoped to one tenant, but the record it lists is global — a tenant
     * admin deleting "a user of this site" would silently revoke that person's
     * access to every OTHER site they work on. Tenant admins get detach()
     * instead, which does what they actually mean.
     */
    public function delete(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        return $user->isSuperadmin();
    }
}
