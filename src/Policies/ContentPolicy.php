<?php

namespace Mmoollllee\Cms\Policies;

use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

class ContentPolicy
{
    public function __construct(
        protected CurrentTenant $currentTenant,
    ) {}

    public function viewAny(User $user): bool
    {
        $tenant = $this->currentTenant->get();

        if ($tenant === null) {
            return false;
        }

        return $user->isSuperadmin() || $tenant->hasUser($user);
    }

    public function view(User $user, Content $content): bool
    {
        return $user->isSuperadmin() || $content->tenant->hasUser($user);
    }

    public function create(User $user): bool
    {
        $tenant = $this->currentTenant->get();

        if ($tenant === null) {
            return false;
        }

        return $user->isSuperadmin() || $tenant->hasUser($user);
    }

    public function update(User $user, Content $content): bool
    {
        return $user->isSuperadmin() || $content->tenant->hasUser($user);
    }

    public function replicate(User $user, Content $content): bool
    {
        return $user->isSuperadmin() || $content->tenant->hasUser($user);
    }

    public function delete(User $user, Content $content): bool
    {
        return $user->isSuperadmin() || $content->tenant->hasUser($user);
    }

    public function restore(User $user, Content $content): bool
    {
        return $user->isSuperadmin() || $content->tenant->hasUser($user);
    }

    public function forceDelete(User $user, Content $content): bool
    {
        return $user->isSuperadmin() || $content->tenant->hasUser($user);
    }
}
