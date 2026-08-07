<?php

namespace Mmoollllee\Cms\Filament\Resources\Locks\Pages;

use Blendbyte\FilamentResourceLock\Resources\LockResource\ManageResourceLocks as BaseManageResourceLocks;
use Mmoollllee\Cms\Filament\Resources\Locks\LockResource;

/**
 * The vendor manager page, repointed at the engine's own
 * {@see LockResource} — the vendor page pins the vendor resource class, which
 * is not the one registered on the panel.
 */
class ManageResourceLocks extends BaseManageResourceLocks
{
    protected static string $resource = LockResource::class;
}
