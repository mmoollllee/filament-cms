<?php

namespace Mmoollllee\Cms\Http\Controllers\Frontend;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Http\Middleware\ResolveActiveRedirects;
use Mmoollllee\Cms\Models\NotFoundLog;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Routing\DetectsUniqueViolations;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;
use Mmoollllee\Cms\Support\Routing\PathSuggestionResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * The async "parallel auto-resolution" endpoint (`GET /_resolve404`) called by the branded
 * 404 page after it has been delivered.
 *
 * - Very-high confidence → create an active `automatic` (302) redirect and tell the browser to
 *   redirect now. Future requests are then handled server-side by {@see ResolveActiveRedirects}.
 * - Medium confidence → return "Meinten Sie?" suggestions and record an inactive `suggested`
 *   redirect for the admin to review.
 *
 * Persistence is idempotent + race-safe (withTrashed()->updateOrCreate semantics in a
 * transaction, duplicate-key swallowed) and never touches an admin-confirmed (`manual`) or a
 * rejected (soft-deleted) row. Writes are gated on the path having been seen `min_hits` times so
 * one-off bot 404s never pollute the redirects table; the current visitor is redirected either way.
 */
class ResolveNotFoundController
{
    use DetectsUniqueViolations;

    public function __construct(
        protected CurrentTenant $currentTenant,
        protected PathSuggestionResolver $suggestions,
        protected PathNormalizer $normalizer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant->get();

        if ($tenant === null || ! config('cms.redirects.enabled', true)) {
            return response()->json(['suggestions' => []]);
        }

        $requested = $request->query('path');
        $path = $this->normalizer->normalize(is_string($requested) ? $requested : null);

        if ($path === '/') {
            return response()->json(['suggestions' => []]);
        }

        $result = $this->suggestions->resolve($tenant, $path);
        $best = $result['best'];
        $autoStatus = (int) config('cms.redirects.auto_status', 302);

        if (config('cms.redirects.auto_redirect', true)
            && $best !== null
            && $best['score'] >= (float) config('cms.redirects.auto_threshold', 0.92)
        ) {
            $this->persist($tenant, $path, $best['path'], RedirectOrigin::Automatic, true, $autoStatus);

            return response()->json(['redirect' => $best['path'], 'status' => $autoStatus]);
        }

        if ($result['suggestions'] !== []) {
            $this->persist($tenant, $path, $result['suggestions'][0]['path'], RedirectOrigin::Suggested, false, $autoStatus);
        }

        return response()->json([
            'suggestions' => array_map(
                fn (array $suggestion): array => [
                    'path' => $suggestion['path'],
                    'title' => $suggestion['title'],
                    'trail' => $suggestion['trail'],
                ],
                $result['suggestions'],
            ),
        ]);
    }

    /**
     * Persist an automatic/suggested redirect, unless gated (too few hits), or an existing row
     * is admin-confirmed (`manual`) or rejected (trashed). No-op on a losing concurrent insert.
     */
    protected function persist(Tenant $tenant, string $fromPath, string $target, RedirectOrigin $origin, bool $active, int $status): void
    {
        if (! $this->eligibleToPersist($tenant, $fromPath)) {
            return;
        }

        try {
            DB::transaction(function () use ($tenant, $fromPath, $target, $origin, $active, $status): void {
                $existing = Redirect::withTrashed()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('from_path', $fromPath)
                    ->first();

                // Never overwrite an admin-confirmed redirect, and never resurrect a rejected one.
                if ($existing !== null && ($existing->trashed() || $existing->origin === RedirectOrigin::Manual)) {
                    return;
                }

                Redirect::$autoWriting = true;

                try {
                    if ($existing !== null) {
                        $existing->forceFill([
                            'to_url' => $target,
                            'to_content_id' => null,
                            'origin' => $origin,
                            'is_active' => $active,
                            'status_code' => $status,
                        ])->save();

                        return;
                    }

                    Redirect::create([
                        'tenant_id' => $tenant->getKey(),
                        'from_path' => $fromPath,
                        'to_url' => $target,
                        'origin' => $origin,
                        'is_active' => $active,
                        'status_code' => $status,
                    ]);
                } finally {
                    Redirect::$autoWriting = false;
                }
            });
        } catch (QueryException $exception) {
            // A concurrent request created the same row first — benign.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }
        }
    }

    protected function eligibleToPersist(Tenant $tenant, string $fromPath): bool
    {
        $minHits = (int) config('cms.redirects.min_hits', 2);

        if ($minHits <= 1) {
            return true;
        }

        $hits = NotFoundLog::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('path', $fromPath)
            ->value('hits') ?? 0;

        return $hits >= $minHits;
    }
}
