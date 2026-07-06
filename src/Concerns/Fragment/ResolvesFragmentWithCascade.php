<?php

namespace Mmoollllee\Cms\Concerns\Fragment;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Fragment;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\CacheKeys;

/**
 * Shared fragment resolution + caching for a {@see Fragment}
 * model. Fragments are keyed by `slug`; resolution cascades own tenant → branding
 * tenant → null, and a per-request array cache (busted on save/delete) avoids repeat
 * queries. The host model needs a `blocks` array cast and a `tenant_id`.
 */
trait ResolvesFragmentWithCascade
{
    public function hasContent(): bool
    {
        return ! empty($this->blocks);
    }

    /**
     * Resolve a fragment model with branding-tenant cascade (own → branding → null).
     */
    public static function resolveFragment(Tenant $tenant, string $slug): ?static
    {
        $all = static::allForTenant($tenant);

        if (isset($all[$slug]) && $all[$slug]->hasContent()) {
            return $all[$slug];
        }

        $brandingTenant = Cms::tenantModel()::defaultBrandingTenant();

        if ($brandingTenant instanceof Tenant && ! $brandingTenant->is($tenant)) {
            $inherited = static::allForTenant($brandingTenant);

            if (isset($inherited[$slug]) && $inherited[$slug]->hasContent()) {
                return $inherited[$slug];
            }
        }

        return null;
    }

    /**
     * Load all fragments for a tenant, keyed by slug, cached per request cycle.
     *
     * @return Collection<string, static>
     */
    public static function allForTenant(Tenant $tenant): Collection
    {
        return Cache::store('array')->remember(
            CacheKeys::fragments($tenant->getKey()),
            null,
            fn () => static::where('tenant_id', $tenant->getKey())->get()->keyBy('slug'),
        );
    }

    protected static function bootResolvesFragmentWithCascade(): void
    {
        $bustCache = function (self $fragment): void {
            Cache::store('array')->forget(CacheKeys::fragments($fragment->tenant_id));
        };

        static::saved($bustCache);
        static::deleted($bustCache);
    }
}
