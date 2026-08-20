<?php

namespace Mmoollllee\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
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

        // Livewire posts every interaction to its own endpoint, which sits
        // outside the panel path — including the panel login, the one request
        // that cannot already be authenticated. Gating it leaves a members-only
        // tenant with a panel nobody can log into: the login screen renders (it
        // is under the panel path) and the button then answers 403. That is the
        // state a site is in for the whole time it is being finished with a
        // customer, so the gate has to let this endpoint through.
        //
        // It does not open the gated frontend. Livewire only acts on a request
        // carrying a valid checksummed snapshot, and the only way for a guest to
        // obtain one is to render the page it came from — which this gate still
        // blocks.
        if ($request->is(ltrim(Livewire::getUpdateUri(), '/'))) {
            return $next($request);
        }

        // Accepting an invitation is how a person GETS access to a tenant, so
        // gating it on already having access locks out exactly the people the
        // invitation was for — the same reasoning as the Livewire exemption
        // above. The route is signed and token-bound; it opens nothing else.
        if ($request->routeIs('cms.tenant-invitations.accept')) {
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
