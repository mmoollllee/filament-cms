<?php

namespace Mmoollllee\Cms\Support\Content;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;

/**
 * The next moment a tenant's content set changes WITHOUT anybody writing:
 * a scheduled `publish_from` arriving or a `publish_until` running out.
 *
 * The content status is time-derived, but the guest-facing caches (sections,
 * sitemap, 404 candidates) are write-invalidated via ContentCacheObserver —
 * a pair that alone would keep expired pages in listings forever and hold
 * scheduled ones back. Every such cache therefore stores with
 * `Cache::remember($key, PublishingTransitions::nextFor($tenant), …)`:
 * the TTL ends exactly at the next transition (null = no transition pending =
 * forever, invalidated by writes as before).
 */
class PublishingTransitions
{
    public static function nextFor(Tenant $tenant): ?CarbonInterface
    {
        $model = Cms::contentModel();

        // SQL MIN on the datetime columns; `Y-m-d H:i:s` strings compare
        // chronologically, so collect()->min() picks the earlier boundary.
        $next = collect([
            $model::query()->whereBelongsTo($tenant)->where('publish_from', '>', now())->min('publish_from'),
            $model::query()->whereBelongsTo($tenant)->where('publish_until', '>', now())->min('publish_until'),
        ])->filter()->min();

        return $next === null ? null : Carbon::parse($next);
    }
}
