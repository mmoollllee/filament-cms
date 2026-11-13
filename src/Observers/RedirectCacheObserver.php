<?php

namespace Mmoollllee\Cms\Observers;

use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\TenantCache;

/**
 * Keeps the per-tenant active-redirect map coherent when redirects change.
 *
 * Moves the tenant's cache generation on once the write commits ({@see TenantCache}) rather
 * than rebuilding the map: a rename cascades one redirect write per moved row, and an eager
 * rebuild per write would resolve every redirect's target N times in one request. It must not
 * run before the commit either — a cache store is not transactional, so a rebuild inside a
 * save that later rolls back leaves the map holding redirects that no longer exist, and a
 * visitor arriving between a forget and the commit puts the old map straight back.
 *
 * Registered via Redirect::observe() in CmsServiceProvider.
 */
class RedirectCacheObserver
{
    public function saved(Redirect $redirect): void
    {
        $this->flush($redirect);
    }

    public function deleted(Redirect $redirect): void
    {
        $this->flush($redirect);
    }

    public function restored(Redirect $redirect): void
    {
        $this->flush($redirect);
    }

    public function forceDeleted(Redirect $redirect): void
    {
        $this->flush($redirect);
    }

    protected function flush(Redirect $redirect): void
    {
        app(TenantCache::class)->flushAfterCommit($redirect, $redirect->tenant_id);
    }
}
