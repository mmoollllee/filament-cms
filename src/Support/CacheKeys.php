<?php

namespace Mmoollllee\Cms\Support;

use Mmoollllee\Cms\Cms;

/**
 * Single source for every cache key the engine reads or invalidates.
 *
 * The per-tenant keys (content, sections, sitemap, candidates, redirects, menu) hold
 * entries built under the tenant's cache generation ({@see TenantCache}); invalidating
 * them means moving that generation on, not forgetting them one by one. The host →
 * tenant lookup sits outside it — it is how the tenant is found in the first place —
 * and is forgotten by domain. Writers and readers MUST agree on the spelling — spread
 * as literals, a renamed key silently splits them into "always stale" / "never cleared".
 *
 * A key's version moves with its payload's shape. The generation-scoped keys took one
 * step together when TenantCache started wrapping every entry as `{generation, value}`:
 * code of an earlier release — a rollback, an old worker during a deploy — reads its own
 * keys then, never a wrapper it would take for a payload.
 */
class CacheKeys
{
    /** The tenant's cache generation — every other per-tenant entry is valid only under it ({@see TenantCache}). */
    public static function generation(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:generation";
    }

    /**
     * Host → Tenant lookup ({@see \Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost}).
     *
     * `v2`: payload is a scalar attribute array (ModelCache, 0.2.6) — pre-0.2.6
     * entries hold serialized models and must never be read again.
     */
    public static function tenantDomain(string $host): string
    {
        return "tenant_domain:v2:{$host}";
    }

    /**
     * Resolved Content (or cached null) per normalized path ({@see \Mmoollllee\Cms\Support\Content\ContentResolver}).
     *
     * `v2`: payload is a scalar attribute array (ModelCache, 0.2.6). `v3`: wrapped by TenantCache.
     */
    public static function content(int|string $tenantId, string $path): string
    {
        return "tenant:{$tenantId}:content:v3:{$path}";
    }

    /**
     * The tenant's onepager section list ({@see \Mmoollllee\Cms\Support\Content\ContentResolver}).
     *
     * `v2`: payload is a list of scalar attribute arrays (ModelCache, 0.2.6). `v3`: wrapped by TenantCache.
     */
    public static function sections(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:sections:v3";
    }

    /** The rendered sitemap XML ({@see \Mmoollllee\Cms\Http\Controllers\Frontend\SitemapController}); `v2`: wrapped by TenantCache. */
    public static function sitemap(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:sitemap:v2";
    }

    /** 404 fuzzy-suggestion candidates ({@see \Mmoollllee\Cms\Support\Routing\PathSuggestionResolver}); `v2`: wrapped by TenantCache. */
    public static function candidates(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:candidates:v2";
    }

    /** The active-redirect map ({@see \Mmoollllee\Cms\Support\Routing\RedirectResolver}); `v2`: wrapped by TenantCache. */
    public static function redirects(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:redirects:v2";
    }

    /** Link-ready menu entries per location ({@see \Mmoollllee\Cms\Models\Menu::linksForLocation()}); `v2`: wrapped by TenantCache. */
    public static function menu(int|string $tenantId, string $location): string
    {
        // The nested-menu opt-in changes the cached shape — a separate key keeps
        // toggling it from serving the other shape until the next menu save.
        $suffix = Cms::hasNestedMenus() ? ':nested' : '';

        return "tenant:{$tenantId}:menu:v2:{$location}{$suffix}";
    }

    /** Redirect hit-count throttle lock ({@see \Mmoollllee\Cms\Support\Routing\HitRecorder}). */
    public static function redirectHitLock(int|string $tenantId, string $fromPath): string
    {
        return "cms:hitlock:r:{$tenantId}:".md5($fromPath);
    }

    /** 404 log throttle lock ({@see \Mmoollllee\Cms\Support\Routing\HitRecorder}). */
    public static function notFoundHitLock(int|string $tenantId, string $path): string
    {
        return "cms:hitlock:n:{$tenantId}:".md5($path);
    }

    /** Request-scoped (array store) fragment collection ({@see \Mmoollllee\Cms\Concerns\Fragment\ResolvesFragmentWithCascade}). */
    public static function fragments(int|string $tenantId): string
    {
        return "fragments.tenant.{$tenantId}";
    }
}
