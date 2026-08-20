<?php

namespace Mmoollllee\Cms\Concerns\Tenant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Enums\TenantVisibility;
use Mmoollllee\Cms\Models\TenantInvitation;

/**
 * Tenant ↔ user membership and the visibility rules built on it.
 *
 * Host-model expectations: a `tenant_user` pivot with a `role` column,
 * a `created_by` column and a `visibility` attribute cast to TenantVisibility.
 */
trait HasTenantUsers
{
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Cms::userModel(), 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Cms::userModel())
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Open and accepted invitations to this tenant — the panel's pending-access
     * list, and how a re-invite finds the row to refresh.
     */
    public function tenantInvitations(): HasMany
    {
        return $this->hasMany(TenantInvitation::class);
    }

    /**
     * Grant membership with a role. Idempotent: an existing member has their
     * role updated instead of gaining a second pivot row, which is what both
     * the direct assignment and an accepted invitation need.
     */
    public function addUser(Authenticatable $user, TenantUserRole $role): void
    {
        $this->users()->syncWithoutDetaching([
            $user->getAuthIdentifier() => ['role' => $role->value],
        ]);
    }

    /**
     * Revoke membership. The account itself is untouched — it keeps every other
     * tenant it belongs to (see {@see \Mmoollllee\Cms\Policies\UserPolicy::detach()}).
     */
    public function removeUser(Authenticatable $user): void
    {
        $this->users()->detach($user->getAuthIdentifier());
    }

    /** Whether someone with this address is already a member (invitation guard). */
    public function hasUserWithEmail(string $email): bool
    {
        return $this->users()
            ->whereRaw('LOWER(users.email) = ?', [mb_strtolower(trim($email))])
            ->exists();
    }

    public function hasUser(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user instanceof User && $user->isSuperadmin()) {
            return true;
        }

        return $this->users()
            ->whereKey($user)
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
