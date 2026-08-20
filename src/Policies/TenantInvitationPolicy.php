<?php

namespace Mmoollllee\Cms\Policies;

use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Models\TenantInvitation;
use Mmoollllee\Cms\Policies\Concerns\AuthorizesTenantAdmins;

/**
 * An invitation is an access grant in waiting, so it follows the same rule as
 * the membership it creates: whoever may manage users of the current tenant may
 * send, resend and withdraw its invitations. Same trait as {@see UserPolicy},
 * so the two cannot drift apart.
 */
class TenantInvitationPolicy
{
    use AuthorizesTenantAdmins;

    public function viewAny(User $user): bool
    {
        return $this->isAdminOfCurrentTenant($user);
    }

    public function view(User $user, TenantInvitation $invitation): bool
    {
        return $this->belongsToCurrentTenant($user, $invitation);
    }

    public function create(User $user): bool
    {
        return $this->isAdminOfCurrentTenant($user);
    }

    public function update(User $user, TenantInvitation $invitation): bool
    {
        return $this->belongsToCurrentTenant($user, $invitation);
    }

    public function delete(User $user, TenantInvitation $invitation): bool
    {
        return $this->belongsToCurrentTenant($user, $invitation);
    }

    /**
     * Admin of the panel's current tenant AND the invitation belongs to that
     * tenant — the list already scopes to it, this guards anything arriving
     * with an id instead.
     */
    protected function belongsToCurrentTenant(User $user, TenantInvitation $invitation): bool
    {
        $tenant = $this->currentTenant();

        return $tenant !== null
            && (int) $invitation->tenant_id === (int) $tenant->getKey()
            && $this->isAdminOfCurrentTenant($user);
    }
}
