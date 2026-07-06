<?php

namespace Mmoollllee\Cms\Observers;

use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;

/**
 * Keeps the per-tenant active-redirect map coherent when redirects change.
 *
 * Eagerly re-warms (forget + rebuild) rather than only forgetting, so the next visitor still
 * gets a cache hit and never pays a cold full-table query — matching the menu/tenant cache
 * observers. Registered via Redirect::observe() in CmsServiceProvider.
 */
class RedirectCacheObserver
{
    public function __construct(protected RedirectResolver $resolver) {}

    public function saved(Redirect $redirect): void
    {
        $this->rewarm($redirect);
    }

    public function deleted(Redirect $redirect): void
    {
        $this->rewarm($redirect);
    }

    public function restored(Redirect $redirect): void
    {
        $this->rewarm($redirect);
    }

    public function forceDeleted(Redirect $redirect): void
    {
        $this->rewarm($redirect);
    }

    protected function rewarm(Redirect $redirect): void
    {
        if ($redirect->tenant_id === null) {
            return;
        }

        $tenant = $redirect->tenant;

        if ($tenant instanceof Tenant) {
            $this->resolver->warm($tenant);

            return;
        }

        $this->resolver->warmById($redirect->tenant_id);
    }
}
