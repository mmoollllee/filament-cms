<?php

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Fragment;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

if (! function_exists('fragment_model')) {
    /**
     * Resolve a fragment model for the current tenant with the branding-tenant cascade.
     *
     * Access builder blocks: fragment_model('slug')?->blocks. The concrete model is
     * resolved via {@see Cms::fragmentModel()}; returns null when no tenant is resolved
     * or the fragment is absent (own → branding → null).
     */
    function fragment_model(string $slug): ?Fragment
    {
        $tenant = app(CurrentTenant::class)->get();
        $fragmentModel = Cms::fragmentModel();

        if ($tenant === null || $fragmentModel === null) {
            return null;
        }

        return $fragmentModel::resolveFragment($tenant, $slug);
    }
}
