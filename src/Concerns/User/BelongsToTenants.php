<?php

namespace Mmoollllee\Cms\Concerns\User;

use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * User ↔ tenant membership plus the Filament tenancy contract methods
 * (FilamentUser, HasTenants, HasDefaultTenant) — host-resolved: panel access
 * and the default tenant follow the domain the user is visiting.
 *
 * Host-model expectations: a `tenant_user` pivot with a `role` column and a
 * boolean-cast `is_superadmin` column. Keep `is_superadmin` OUT of $fillable —
 * it is a global authorization kill-switch, set it explicitly (factory state /
 * seeder), never from request data.
 */
trait BelongsToTenants
{
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Cms::tenantModel())
            ->withPivot('role')
            ->withTimestamps();
    }

    public function isSuperadmin(): bool
    {
        return (bool) $this->is_superadmin;
    }

    public function belongsToTenant(Tenant $tenant): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->tenants()
            ->whereKey($tenant)
            ->exists();
    }

    public function tenantRole(Tenant $tenant): ?TenantUserRole
    {
        if ($this->isSuperadmin()) {
            return TenantUserRole::Admin;
        }

        $role = $this->tenants()
            ->whereKey($tenant)
            ->value('tenant_user.role');

        return $role !== null ? TenantUserRole::from($role) : null;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Tenant) {
            return false;
        }

        return $this->belongsToTenant($tenant);
    }

    public function getTenants(Panel $panel): array|Collection
    {
        if ($this->isSuperadmin()) {
            return Cms::tenantModel()::query()
                ->orderBy('name')
                ->get();
        }

        return $this->tenants()
            ->orderBy('name')
            ->get();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant instanceof Tenant && $this->canAccessTenant($tenant)) {
            return $tenant;
        }

        $host = request()->getHost();

        if (filled($host)) {
            $tenant = Cms::tenantModel()::query()
                ->where('primary_domain', $host)
                ->first();

            if ($tenant instanceof Tenant && $this->canAccessTenant($tenant)) {
                return $tenant;
            }
        }

        $tenants = $this->getTenants($panel);

        if ($tenants instanceof Collection) {
            return $tenants->first();
        }

        return $tenants[0] ?? null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        $tenant = app(CurrentTenant::class)->get();

        if ($tenant instanceof Tenant) {
            return $this->belongsToTenant($tenant);
        }

        $host = request()->getHost();

        if (blank($host)) {
            return false;
        }

        $tenant = Cms::tenantModel()::query()
            ->where('primary_domain', $host)
            ->first();

        return $tenant !== null && $this->belongsToTenant($tenant);
    }
}
