<?php

namespace Mmoollllee\Cms\Support\Routing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Models\NotFoundLog;
use Mmoollllee\Cms\Models\Redirect;

use Mmoollllee\Cms\Support\CacheKeys;
use function Illuminate\Support\defer;

/**
 * Records redirect usage + 404 occurrences WITHOUT touching the database on the request's
 * critical path. Writes are deferred until after the response is flushed and throttled to at
 * most one DB write per key per minute, so a hot redirect or a bot storm on a dead URL never
 * slows a response or floods the DB (counts are therefore approximate — acceptable for stats).
 *
 * `defer()->always()` is required (not plain `defer()`): a 404 render is a 4xx response, and
 * plain deferred functions are skipped for 4xx/5xx.
 */
class HitRecorder
{
    use DetectsUniqueViolations;

    public function __construct(protected PathNormalizer $normalizer) {}

    /**
     * Count a served redirect. Throttled to ≤1 DB write/min per (tenant, from_path).
     */
    public function recordRedirectHit(Tenant $tenant, string $fromPath): void
    {
        if (! config('cms.redirects.count_hits', true)) {
            return;
        }

        $tenantId = $tenant->getKey();
        $fromPath = $this->normalizer->normalize($fromPath);

        if (! Cache::add(CacheKeys::redirectHitLock($tenantId, $fromPath), 1, now()->addSeconds(60))) {
            return;
        }

        defer(function () use ($tenantId, $fromPath): void {
            Redirect::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('from_path', $fromPath)
                ->update([
                    'hits' => DB::raw('hits + 1'),
                    'last_hit_at' => now(),
                ]);
        })->always();
    }

    /**
     * Record a 404 for a path, upserting the tenant's NotFoundLog row. Ignores obvious probe
     * noise. Throttled to ≤1 DB write/min per (tenant, path).
     */
    public function record404(Tenant $tenant, string $path, ?string $referer = null, ?string $userAgent = null): void
    {
        if (! config('cms.redirects.log_not_found', true)) {
            return;
        }

        $path = $this->normalizer->normalize($path);

        if ($this->isIgnored($path)) {
            return;
        }

        // The `path` column is VARCHAR(255); cap to it (like referer/user_agent below) so a
        // long crafted 404 path can't throw a "data too long" error inside the deferred write
        // (silently losing the log) or truncate-and-collide on the unique (tenant_id, path) index.
        $path = Str::limit($path, 255, '');

        $tenantId = $tenant->getKey();

        if (! Cache::add(CacheKeys::notFoundHitLock($tenantId, $path), 1, now()->addSeconds(60))) {
            return;
        }

        $referer = $referer !== null ? Str::limit($referer, 255, '') : null;
        $userAgent = $userAgent !== null ? Str::limit($userAgent, 255, '') : null;

        defer(function () use ($tenantId, $path, $referer, $userAgent): void {
            try {
                $log = NotFoundLog::query()->firstOrNew([
                    'tenant_id' => $tenantId,
                    'path' => $path,
                ]);

                $log->hits = ($log->hits ?? 0) + 1;
                $log->first_seen_at ??= now();
                $log->last_seen_at = now();

                if ($referer !== null) {
                    $log->last_referer = $referer;
                }

                if ($userAgent !== null) {
                    $log->last_user_agent = $userAgent;
                }

                $log->save();
            } catch (QueryException $exception) {
                // A concurrent first-time 404 for the same (tenant_id, path) won the insert race
                // (the per-minute lock only narrows the window on a shared atomic cache store).
                // Fall back to an atomic increment instead of throwing inside the deferred runner.
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                NotFoundLog::query()
                    ->where('tenant_id', $tenantId)
                    ->where('path', $path)
                    ->update(array_filter([
                        'hits' => DB::raw('hits + 1'),
                        'last_seen_at' => now(),
                        'last_referer' => $referer,
                        'last_user_agent' => $userAgent,
                    ], fn ($value): bool => $value !== null));
            }
        })->always();
    }

    protected function isIgnored(string $path): bool
    {
        if (mb_strlen($path) > 2048) {
            return true;
        }

        $lower = strtolower($path);

        foreach ((array) config('cms.redirects.ignore_extensions', []) as $extension) {
            if (str_ends_with($lower, '.'.ltrim((string) $extension, '.'))) {
                return true;
            }
        }

        return false;
    }
}
