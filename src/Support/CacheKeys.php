<?php

namespace Mmoollllee\Cms\Support;

/**
 * Single source for every cache key the engine reads or invalidates.
 *
 * The keys form one coherent per-tenant cache: writers (Menu, ContentResolver,
 * RedirectResolver, SitemapController, PathSuggestionResolver,
 * ResolveTenantFromHost) and invalidators (ContentCacheObserver,
 * ClearTenantCacheCommand) MUST agree on the spelling — spread as literals, a
 * renamed key silently splits them into "always stale" / "never cleared".
 */
class CacheKeys
{
    /** Host → Tenant lookup ({@see \Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost}). */
    public static function tenantDomain(string $host): string
    {
        return "tenant_domain:{$host}";
    }

    /** Resolved Content (or cached null) per normalized path ({@see \Mmoollllee\Cms\Support\Content\ContentResolver}). */
    public static function content(int|string $tenantId, string $path): string
    {
        return "tenant:{$tenantId}:content:{$path}";
    }

    /** The tenant's onepager section list ({@see \Mmoollllee\Cms\Support\Content\ContentResolver}). */
    public static function sections(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:sections";
    }

    /** The rendered sitemap XML ({@see \Mmoollllee\Cms\Http\Controllers\Frontend\SitemapController}). */
    public static function sitemap(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:sitemap";
    }

    /** 404 fuzzy-suggestion candidates ({@see \Mmoollllee\Cms\Support\Routing\PathSuggestionResolver}). */
    public static function candidates(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:candidates";
    }

    /** The active-redirect map ({@see \Mmoollllee\Cms\Support\Routing\RedirectResolver}). */
    public static function redirects(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:redirects";
    }

    /** Link-ready menu entries per location ({@see \Mmoollllee\Cms\Models\Menu::linksForLocation()}). */
    public static function menu(int|string $tenantId, string $location): string
    {
        return "tenant:{$tenantId}:menu:{$location}";
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
