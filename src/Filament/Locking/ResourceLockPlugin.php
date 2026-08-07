<?php

namespace Mmoollllee\Cms\Filament\Locking;

use Blendbyte\FilamentResourceLock\ResourceLockPlugin as BaseResourceLockPlugin;
use Filament\Panel;

/**
 * The locking plugin with its resource registration narrowed to what a
 * tenant panel can safely serve.
 *
 * The vendor plugin registers its lock manager AND its audit-log resource
 * unconditionally. The audit resource is a problem on both counts: the engine
 * does not ship its table (`resource_lock_audit`, audit is off by default), so
 * its route 500s, and the vendor leaves it ungated — publishing the vendor
 * migrations to fix the 500 would turn it into a cross-tenant activity log
 * readable by every editor. It is therefore not registered at all; an app that
 * wants the audit trail publishes the vendor migration and registers
 * {@see \Blendbyte\FilamentResourceLock\Resources\AuditResource} itself, gated.
 *
 * The manager is kept, repointed at {@see \Mmoollllee\Cms\Filament\Resources\Locks\LockResource},
 * which opts out of tenancy per-class instead of flipping the framework-wide
 * default.
 */
class ResourceLockPlugin extends BaseResourceLockPlugin
{
    public function register(Panel $panel): void
    {
        $panel->resources([
            $this->getResourceClass(),
        ]);
    }
}
