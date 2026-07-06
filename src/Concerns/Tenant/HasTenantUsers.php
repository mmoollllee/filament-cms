<?php

namespace Mmoollllee\Cms\Concerns\Tenant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantVisibility;

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
