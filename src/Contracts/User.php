<?php

namespace Mmoollllee\Cms\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Mmoollllee\Cms\Enums\TenantUserRole;

/**
 * Contract implemented by an application's authenticatable user model (the
 * concrete class is resolved via Cms::userModel(), falling back to the
 * framework auth config). Declares the tenant-authorization methods the shared
 * policies call; the framework passes instances as the authenticated user.
 */
interface User extends Authenticatable
{
    /** Whether this user is a global superadmin (bypasses tenant scoping). */
    public function isSuperadmin(): bool;

    /** The user's role within the given tenant, or null if not a member. */
    public function tenantRole(Tenant $tenant): ?TenantUserRole;
}
