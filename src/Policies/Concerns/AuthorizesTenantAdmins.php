<?php

namespace Mmoollllee\Cms\Policies\Concerns;

use Mmoollllee\Cms\Concerns\ResolvesPanelTenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantUserRole;

/**
 * "Admin of the site currently being administered" — the rule behind everything
 * to do with ACCESS (members, invitations), as opposed to content, which every
 * member may edit.
 *
 * The tenant comes from Filament rather than the host resolver because these
 * checks only ever run inside the panel, where the panel URL is the authority
 * on which site is being administered.
 */
trait AuthorizesTenantAdmins
{
    use ResolvesPanelTenant;

    protected function isAdminOfCurrentTenant(User $user): bool
    {
        $tenant = $this->currentTenant();

        if ($tenant === null) {
            return false;
        }

        return $user->isSuperadmin() || $user->tenantRole($tenant) === TenantUserRole::Admin;
    }
}
