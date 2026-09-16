<?php

namespace Mmoollllee\Cms\Concerns\Tenant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantVisibility;
use Mmoollllee\FilamentTenantAccess\Concerns\HasTenantMembers;

/**
 * Tenant ↔ user membership and the visibility rules built on it.
 *
 * The membership itself — `users()`, `tenantInvitations()`, `addUser()`,
 * `removeUser()`, `hasUserWithEmail()` — comes from filament-tenant-access,
 * so the access list, invitations and direct assignment behave exactly as in
 * every other application built on it. What stays here is what the CMS means
 * differently: a site has a creator rather than an owner, and a superadmin is
 * a member of every site.
 *
 * Host-model expectations: a `tenant_user` pivot with a `role` column,
 * a `created_by` column and a `visibility` attribute cast to TenantVisibility.
 */
trait HasTenantUsers
{
    use HasTenantMembers;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Cms::userModel(), 'created_by');
    }

    /**
     * Superadmins belong to every site. Asked of the database rather than of a
     * loaded relation, because visibility checks run on the long-lived current
     * tenant.
     */
    public function hasUser(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user instanceof User && $user->isSuperadmin()) {
            return true;
        }

        return $this->users()
            ->whereKey($user->getAuthIdentifier())
            ->exists();
    }

    public function isVisibleTo(?Authenticatable $user): bool
    {
        if ($this->visibility === TenantVisibility::Archived) {
            return $this->hasUser($user);
        }

        if ($this->visibility === TenantVisibility::Public) {
            return true;
        }

        return $this->hasUser($user);
    }
}
