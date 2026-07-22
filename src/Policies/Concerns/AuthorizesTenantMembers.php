<?php

namespace Mmoollllee\Cms\Policies\Concerns;

use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;

/**
 * Shared tenant-membership rules of the media policies: superadmins bypass
 * everything via before(), members act within tenants they belong to. The
 * using policy injects {@see \Mmoollllee\Cms\Support\Tenancy\CurrentTenant}
 * as $currentTenant.
 */
trait AuthorizesTenantMembers
{
    public function before(User $user): ?bool
    {
        return $user->isSuperadmin() ? true : null;
    }

    protected function memberOfCurrentTenant(User $user): bool
    {
        return $this->belongsToUserTenant($user, $this->currentTenant->get());
    }

    protected function belongsToUserTenant(User $user, mixed $tenant): bool
    {
        return $tenant instanceof Tenant && $tenant->hasUser($user);
    }
}
