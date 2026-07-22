<?php

namespace Mmoollllee\Cms\Policies;

use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Policies\Concerns\AuthorizesTenantMembers;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

/**
 * Media items: tenant members manage the current tenant's library, superadmins
 * everything. Registered on the plugin model in CmsServiceProvider (vendor
 * models are not auto-discovered); the plugin's LegacyPolicyAuthorization
 * bridges these methods onto its FileAbility checks.
 *
 * Visibility itself is enforced by the driver's tenant scope — the policy is
 * the write/second line of defense.
 */
class MediaItemPolicy
{
    use AuthorizesTenantMembers;

    public function __construct(
        protected CurrentTenant $currentTenant,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->memberOfCurrentTenant($user);
    }

    public function view(User $user, MediaLibraryItem $item): bool
    {
        return $this->memberOfItemTenant($user, $item);
    }

    public function create(User $user, ?MediaLibraryFolder $parentFolder = null): bool
    {
        if (! $this->memberOfCurrentTenant($user)) {
            return false;
        }

        return $parentFolder === null || $this->belongsToUserTenant($user, $parentFolder->tenant);
    }

    public function update(User $user, MediaLibraryItem $item): bool
    {
        return $this->memberOfItemTenant($user, $item);
    }

    public function replace(User $user, MediaLibraryItem $item): bool
    {
        return $this->memberOfItemTenant($user, $item);
    }

    public function duplicate(User $user, MediaLibraryItem $item): bool
    {
        return $this->memberOfItemTenant($user, $item);
    }

    public function delete(User $user, MediaLibraryItem $item): bool
    {
        return $this->memberOfItemTenant($user, $item);
    }

    protected function memberOfItemTenant(User $user, MediaLibraryItem $item): bool
    {
        // Items without a tenant stamp should not exist in CMS panels
        // (tenancy is always on) — treat them as superadmin-only.
        return $this->belongsToUserTenant($user, $item->tenant);
    }
}
