<?php

namespace Mmoollllee\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\ModelCache;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the current tenant from the request's Host header.
 *
 * Looks up the Tenant whose `primary_domain` matches the request host.
 * Stores the tenant in CurrentTenant (singleton) and as a request attribute
 * so downstream controllers and views can access it.
 *
 * Applied to all frontend routes. Returns 404 if no tenant matches.
 *
 * @see CurrentTenant — value holder for the resolved tenant
 */
class ResolveTenantFromHost
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
        $host = $request->getHost();
        $cacheKey = CacheKeys::tenantDomain($host);

        // Cached as a scalar attribute array (ModelCache) — L13 cache stores refuse
        // to unserialize objects (`cache.serializable_classes = false` default).
        $tenant = ModelCache::unpack(Cms::tenantModel(), Cache::get($cacheKey));

        if ($tenant === null) {
            $tenant = Cms::tenantModel()::query()
                ->where('primary_domain', $host)
                ->first();

            // Cache only a real hit — NEVER the miss. The host comes from the (spoofable)
            // Host header, so rememberForever'ing a null miss would let an attacker grow the
            // cache store without bound with junk hosts, and would also shadow a domain later
            // assigned to a tenant until a manual cache clear.
            if ($tenant !== null) {
                Cache::forever($cacheKey, ModelCache::pack($tenant));
            }
        }

        if ($tenant === null) {
            throw new NotFoundHttpException;
        }

        $this->currentTenant->set($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
