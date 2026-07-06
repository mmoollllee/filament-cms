<?php

namespace Mmoollllee\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves an active redirect for the current tenant BEFORE content is looked up, so a redirect
 * can shadow live content (redirection.me parity) and pre-empt the visibility gate.
 *
 * Runs only on the catch-all frontend route (`content.show`) — never on robots/sitemap/panel/etc.
 * A hit is a single cached-map lookup ({@see RedirectResolver}), so a valid page adds zero DB
 * queries. Registered between ResolveTenantFromHost and EnsureTenantIsVisible.
 */
class ResolveActiveRedirects
{
    public function __construct(
        protected CurrentTenant $currentTenant,
        protected RedirectResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() || ! $request->routeIs('content.show')) {
            return $next($request);
        }

        $tenant = $this->currentTenant->get();

        if ($tenant !== null) {
            $path = $request->route('path');
            $redirect = $this->resolver->resolve($tenant, is_string($path) ? $path : $request->path());

            if ($redirect !== null) {
                return $redirect;
            }
        }

        return $next($request);
    }
}
