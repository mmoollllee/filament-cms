<?php

namespace Mmoollllee\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnsureTenantIsVisible
{
    public function __construct(
        protected CurrentTenant $currentTenant,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Exempt panel requests. Read the path from the registered panel (matching
        // NotFoundRenderer) so a customized ->path() is still exempted, not just
        // the default 'panel'.
        $panelPath = Cms::panelPath();

        if ($request->is($panelPath) || $request->is($panelPath.'/*')) {
            return $next($request);
        }

        $tenant = $this->currentTenant->get();

        if ($tenant === null) {
            throw new NotFoundHttpException;
        }

        if ($tenant->isVisibleTo($request->user())) {
            return $next($request);
        }

        throw new AccessDeniedHttpException;
    }
}
