<?php

namespace Mmoollllee\Cms\Support\Routing;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Observers\RedirectCacheObserver;
use Mmoollllee\Cms\Support\CacheKeys;

/**
 * Resolves an incoming path to an active redirect for a tenant.
 *
 * The whole set of active redirects is materialized once per tenant into a `from_path → target`
 * map cached with `rememberForever` (keyed `tenant:{id}:redirects`). A lookup is then a single
 * cache read + array access — zero DB queries on a hit — so redirects can run BEFORE content
 * resolution (shadowing live content) without measurably affecting page load. The map is eagerly
 * re-warmed by {@see RedirectCacheObserver} and on content changes.
 */
class RedirectResolver
{
    public function __construct(
        protected PathNormalizer $normalizer,
        protected HitRecorder $hitRecorder,
    ) {}

    /**
     * Resolve a path to a RedirectResponse, or null when no active redirect matches.
     */
    public function resolve(Tenant $tenant, ?string $path): ?RedirectResponse
    {
        if (! config('cms.redirects.enabled', true)) {
            return null;
        }

        $normalized = $this->normalizer->normalize($path);
        $hit = $this->activeMap($tenant)[$normalized] ?? null;

        if ($hit === null || $hit['to'] === $normalized) {
            return null;
        }

        $this->hitRecorder->recordRedirectHit($tenant, $normalized);

        return new RedirectResponse($hit['to'], $hit['status']);
    }

    /**
     * The tenant's active-redirect map: `[normalizedFromPath => ['to' => url, 'status' => code]]`.
     *
     * @return array<string, array{to: string, status: int}>
     */
    public function activeMap(Tenant $tenant): array
    {
        return Cache::rememberForever(
            $this->cacheKey($tenant->getKey()),
            fn (): array => $this->buildMap($tenant),
        );
    }

    public function forget(Tenant $tenant): void
    {
        $this->forgetById($tenant->getKey());
    }

    public function forgetById(int|string $tenantId): void
    {
        Cache::forget($this->cacheKey($tenantId));
    }

    /**
     * Forget then immediately re-populate the map, so the next visitor still gets a cache hit
     * (mirrors the menu/tenant cache observers, not the forget-only content observer).
     */
    public function warm(Tenant $tenant): void
    {
        $this->forget($tenant);
        $this->activeMap($tenant);
    }

    public function warmById(int|string $tenantId): void
    {
        $tenant = Cms::tenantModel()::query()->find($tenantId);

        if ($tenant !== null) {
            $this->warm($tenant);
        }
    }

    protected function cacheKey(int|string $tenantId): string
    {
        return CacheKeys::redirects($tenantId);
    }

    /**
     * @return array<string, array{to: string, status: int}>
     */
    protected function buildMap(Tenant $tenant): array
    {
        $map = [];

        Redirect::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_active', true)
            ->with('toContent')
            ->get()
            ->each(function (Redirect $redirect) use (&$map, $tenant): void {
                // A content target belongs to this same tenant; hand it the known tenant so
                // resolvedPath() doesn't re-query tenantModel::find() per row when the request
                // has no matching CurrentTenant (console re-warm, queue, etc.).
                $redirect->toContent?->setRelation('tenant', $tenant);

                $from = $this->normalizer->normalize($redirect->from_path);
                $to = $redirect->resolvedTarget();

                // Skip broken (deleted/non-routable) targets and self-loops.
                if ($to === null || $from === $to) {
                    return;
                }

                $map[$from] = ['to' => $to, 'status' => (int) $redirect->status_code];
            });

        return $map;
    }
}
