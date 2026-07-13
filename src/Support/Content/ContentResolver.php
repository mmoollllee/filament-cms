<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\ModelCache;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;

/**
 * Resolves URL paths to Content records for a given tenant.
 *
 * Central lookup service used by the frontend controllers to find the Content
 * record that matches an incoming request path. Also determines how each
 * Content participates in the onepager structure (section, shell root, anchor).
 *
 * Resolution chain:
 *   host-resolution middleware → content controller → ContentResolver::findByPath()
 *   → TemplateResolver::resolve() → Blade view
 *
 * @see TemplateResolver — next step after content is found
 */
class ContentResolver
{
    public function __construct(
        protected ContentBlueprintRegistry $blueprints,
        protected PathNormalizer $normalizer,
    ) {}

    /**
     * Find a Content record by its URL path within a tenant.
     *
     * First attempts a direct `path` column match. If that fails, falls back
     * to computing resolvedPath() for every visible record (handles blueprint-
     * generated paths that aren't stored literally in the database).
     */
    public function findByPath(Tenant $tenant, ?string $path, ?Authenticatable $user = null): ?Content
    {
        $normalizedPath = $this->normalizePath($path);

        if ($user !== null) {
            return $this->resolveFindByPath($tenant, $normalizedPath, $user);
        }

        $key = CacheKeys::content($tenant->getKey(), $normalizedPath);

        // `false` = not cached; `null` = a cached 404 (negative hit); an attribute
        // array (ModelCache) = a real hit. Payloads stay scalar so L13's
        // `serializable_classes = false` cache stores can round-trip them.
        $cached = Cache::get($key, false);

        if ($cached === null) {
            return null;
        }

        if (is_array($cached)) {
            $content = ModelCache::unpack(Cms::contentModel(), $cached);

            if ($content !== null) {
                $content->setRelation('tenant', $tenant);

                return $content;
            }
            // Malformed payload → fall through and re-resolve.
        }

        $content = $this->resolveFindByPath($tenant, $normalizedPath);

        if ($content !== null) {
            // Real pages are cached indefinitely (busted by ContentCacheObserver on write).
            Cache::forever($key, ModelCache::pack($content));

            return $content;
        }

        // Cache 404s only BRIEFLY: this absorbs bursts of bots hammering the same dead URL
        // (the fallback in resolveFindByPath() scans all content) without letting an attacker
        // enumerating distinct paths grow the cache store without bound (as rememberForever did).
        Cache::put($key, null, now()->addSeconds(self::MISS_CACHE_TTL));

        return null;
    }

    /** Seconds a negative (404) path lookup is cached — long enough to shed bot floods, short enough to bound growth. */
    protected const MISS_CACHE_TTL = 60;

    protected function resolveFindByPath(Tenant $tenant, string $normalizedPath, ?Authenticatable $user = null): ?Content
    {
        $content = Cms::contentModel()::query()
            ->visibleTo($tenant, $user)
            ->where('path', $normalizedPath)
            ->first();

        if ($content !== null) {
            $content->setRelation('tenant', $tenant);

            // A non-routable blueprint has no URL: ignore a stale/leftover `path`
            // column (e.g. a type that became non-routable after rows were saved)
            // so it cannot be served via the catch-all route.
            if ($this->isRoutable($content, $tenant)) {
                return $content;
            }
        }

        return Cms::contentModel()::query()
            ->visibleTo($tenant, $user)
            // resolvedPath() consults the parent for parent-driven paths — eager
            // load it so the full scan stays two queries instead of N+1.
            ->with('parent')
            ->get()
            ->first(function (Content $content) use ($normalizedPath, $tenant): bool {
                $content->setRelation('tenant', $tenant);

                return $content->resolvedPath() === $normalizedPath;
            });
    }

    /**
     * All top-level contents that participate in the onepager layout.
     *
     * Used by the onepager shell controller to build the list of scrollable
     * sections. Only root-level (parent_id = null), non-legal, routable
     * contents qualify.
     *
     * @return Collection<int, Content>
     */
    public function sections(Tenant $tenant, ?Authenticatable $user = null): Collection
    {
        if ($user !== null) {
            return $this->resolveSections($tenant, $user);
        }

        // Cached as a list of attribute arrays (ModelCache) — see findByPath().
        $sections = ModelCache::unpackMany(Cms::contentModel(), Cache::rememberForever(
            CacheKeys::sections($tenant->getKey()),
            fn (): array => ModelCache::packMany($this->resolveSections($tenant)),
        ));

        return $sections ?? $this->resolveSections($tenant);
    }

    protected function resolveSections(Tenant $tenant, ?Authenticatable $user = null): Collection
    {
        return Cms::contentModel()::query()
            ->visibleTo($tenant, $user)
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->orderBy('title')
            ->get()
            ->filter(fn (Content $content): bool => $this->isOnepagerSection($content, $tenant))
            ->values();
    }

    public function isOnepagerSection(Content $content, Tenant $tenant): bool
    {
        return $content->parent_id === null
            && $this->participatesInOnepager($content, $tenant)
            && $this->isRoutable($content, $tenant);
    }

    /**
     * Find the onepager root section that "owns" the given content.
     *
     * Walks up the parent chain looking for a Content that is both an onepager
     * section and a shell root. Returns null for content that doesn't belong
     * to any onepager.
     *
     * Used by the content controller to decide whether to delegate to the
     * onepager shell controller (when the content IS the section root).
     */
    public function onepagerSectionFor(Content $content, Tenant $tenant): ?Content
    {
        if ($this->isOnepagerShellRoot($content, $tenant)) {
            return $content;
        }

        if (! $this->isRoutable($content, $tenant)) {
            return null;
        }

        $ancestor = $content->parent;

        while ($ancestor instanceof Content) {
            if ($this->isOnepagerShellRoot($ancestor, $tenant)) {
                return $ancestor;
            }

            $ancestor = $ancestor->parent;
        }

        return null;
    }

    public function normalizePath(?string $path): string
    {
        return $this->normalizer->normalize($path);
    }

    /**
     * Compute the hash anchor ID for a content section in the onepager.
     *
     * Returns the last path segment as anchor (e.g. "/services" → "services").
     * Used by the frontend JS to scroll to sections via `/#anchor`.
     */
    public function onepagerAnchor(Content $content, Tenant $tenant): ?string
    {
        if (! $this->isOnepagerSection($content, $tenant) || $this->usesOnepagerShell($content, $tenant)) {
            return null;
        }

        $resolvedPath = $content->resolvedPath();

        if ($resolvedPath === null || $resolvedPath === '/') {
            return null;
        }

        $pathSegment = Str::afterLast(trim($resolvedPath, '/'), '/');

        return filled($pathSegment) ? $pathSegment : null;
    }

    /**
     * The href a link to this content should use on an onepager site: hash-anchor
     * sections link as `/#anchor`, everything else (shell-backed sections, plain
     * pages) by its own path. Part of the consumer-facing engine API (app menus/
     * teasers build section links with it).
     */
    public function onepagerHref(Content $content, Tenant $tenant): ?string
    {
        $anchor = $this->onepagerAnchor($content, $tenant);

        if ($anchor !== null) {
            return '/#'.$anchor;
        }

        return $content->resolvedPath();
    }

    protected function isRoutable(Content $content, Tenant $tenant): bool
    {
        return $this->blueprints
            ->find($content->content_type, $tenant->site_key)
            ?->isRoutable() ?? filled($content->path);
    }

    protected function participatesInOnepager(Content $content, Tenant $tenant): bool
    {
        return $this->blueprints
            ->find($content->content_type, $tenant->site_key)
            ?->participatesInOnepager() ?? true;
    }

    protected function usesOnepagerShell(Content $content, Tenant $tenant): bool
    {
        // Teaser mode implies standalone rendering (no shell, gets hash anchor).
        if (data_get($content->payload, 'has_teaser') === true) {
            return false;
        }

        // Allow content-level override via payload (e.g. variant-dependent shell behavior).
        $override = data_get($content->payload, 'uses_onepager_shell');

        if ($override !== null) {
            return (bool) $override;
        }

        return $this->blueprints
            ->find($content->content_type, $tenant->site_key)
            ?->usesOnepagerShell() ?? true;
    }

    protected function isOnepagerShellRoot(Content $content, Tenant $tenant): bool
    {
        return $this->isOnepagerSection($content, $tenant)
            && $this->usesOnepagerShell($content, $tenant);
    }
}
