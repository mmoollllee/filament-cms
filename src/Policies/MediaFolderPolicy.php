<?php

namespace Mmoollllee\Cms\Policies;

use Filament\Facades\Filament;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Policies\Concerns\AuthorizesTenantMembers;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;

/**
 * Media folders — counterpart to {@see MediaItemPolicy}. Root-folder creation
 * additionally requires a Filament tenant context: without it the new folder
 * would be stamped `tenant NULL` and be invisible to its own creator
 * (nest-proven rule).
 */
class MediaFolderPolicy
{
    use AuthorizesTenantMembers;

    public function __construct(
        protected CurrentTenant $currentTenant,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->memberOfCurrentTenant($user);
    }

    public function view(User $user, MediaLibraryFolder $folder): bool
    {
        return $this->belongsToUserTenant($user, $folder->tenant);
    }

    public function create(User $user, ?MediaLibraryFolder $parentFolder = null): bool
    {
        if ($parentFolder === null) {
            return Filament::getTenant() !== null && $this->memberOfCurrentTenant($user);
        }

        return $this->belongsToUserTenant($user, $parentFolder->tenant);
    }

    public function update(User $user, MediaLibraryFolder $folder): bool
    {
        return $this->belongsToUserTenant($user, $folder->tenant);
    }

    public function delete(User $user, MediaLibraryFolder $folder): bool
    {
        return $this->belongsToUserTenant($user, $folder->tenant);
    }
}
