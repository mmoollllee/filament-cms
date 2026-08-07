<?php

namespace Mmoollllee\Cms\Filament\Resources\Locks;

use Blendbyte\FilamentResourceLock\Resources\LockResource as BaseLockResource;
use Mmoollllee\Cms\Filament\Resources\Locks\Pages\ManageResourceLocks;

/**
 * The plugin's lock manager, made safe for a tenant panel: `resource_locks` has
 * no tenant column, so it opts out of tenancy scoping the way the engine's other
 * unscoped resources do — by redeclaring the property, NEVER via
 * `scopeToTenant(false)`. That setter writes `$isScopedToTenant` on the
 * {@see \Filament\Resources\Resource} base class, which every resource that does
 * not redeclare it shares, switching tenant scoping off panel-wide.
 *
 * Being genuinely cross-tenant, it stays out of the navigation and behind the
 * `cms.manage-locks` gate ({@see \Mmoollllee\Cms\Filament\Locking\ResourceLockPlugin}).
 */
class LockResource extends BaseLockResource
{
    protected static bool $isScopedToTenant = false;

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ManageResourceLocks::route('/'),
        ];
    }
}
