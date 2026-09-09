<?php

namespace Mmoollllee\Cms\Observers;

use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;

/**
 * Keeps the per-tenant active-redirect map coherent when redirects change.
 *
 * Forgets rather than eagerly rebuilding, for the same reason ContentCacheObserver does: a
 * rename cascades one redirect write per moved row, and re-warming per write rebuilds the
 * whole tenant map N times in one request (each rebuild resolving every redirect's target).
 * It also has to be forget: warm() writes through Cache::rememberForever, the panel wraps a
 * save in a transaction, and a cache store is not transactional — an eager rebuild inside a
 * save that later rolls back leaves the map holding redirects that no longer exist.
 *
 * Registered via Redirect::observe() in CmsServiceProvider.
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

        // By id, not via the relation: reading $redirect->tenant lazy-loads a row per write,
        // and the map is rebuilt lazily on the next request anyway.
        $this->resolver->forgetById($redirect->tenant_id);
    }
}
