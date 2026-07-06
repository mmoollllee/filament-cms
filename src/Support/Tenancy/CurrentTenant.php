<?php

namespace Mmoollllee\Cms\Support\Tenancy;

use Mmoollllee\Cms\Contracts\Tenant;

/**
 * Request-scoped holder for the resolved tenant (bound as a singleton).
 *
 * Set by the host-resolution middleware and read across the engine, models,
 * and views to scope queries and branding to the active tenant.
 */
class CurrentTenant
{
    public function __construct(
        protected ?Tenant $tenant = null,
    ) {}

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }
}
