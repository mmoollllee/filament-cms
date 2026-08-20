<?php

namespace Mmoollllee\Cms\Concerns;

use Filament\Facades\Filament;
use Mmoollllee\Cms\Contracts\Tenant;

/**
 * The site currently being administered, or null.
 *
 * `Filament::getTenant()` is typed to Model, so every caller has to narrow it
 * before it can call anything on the contract — and every caller that writes
 * that narrowing itself is a place the rule can drift. Panel-side code only:
 * engine and frontend code resolves the tenant from the host instead
 * ({@see \Mmoollllee\Cms\Support\Tenancy\CurrentTenant}).
 */
trait ResolvesPanelTenant
{
    protected function currentTenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
