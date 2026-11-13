<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\ModelCache;
use Mmoollllee\Cms\Support\Preview\PreviewMode;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;
use Mmoollllee\Cms\Support\TenantCache;

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
     * The stored `path` column is the address ({@see Content::resolvedPath()}), so this is
     * an indexed lookup for a hit and a miss alike — it no longer falls back to composing
     * the path of every visible record, which made each distinct dead URL cost a query per
     * row of the tenant.
     *
     * A path spelled in another letter case ("/Jobs") finds its page too — the
     * exact spelling wins — so the caller can fold the variant into the page's
     * own address with a redirect
     * ({@see \Mmoollllee\Cms\Http\Controllers\Frontend\ContentShowController}).
     */
    public function findByPath(Tenant $tenant, ?string $path, ?Authenticatable $user = null): ?Content
    {
        $normalizedPath = $this->normalizePath($path);

        // Only an ACTIVE preview changes what visibleTo() returns (and overlays
        // drafts on retrieval) — a merely logged-in user sees exactly the guest
        // result, so the warm cache serves them too.
        if ($user !== null && app(PreviewMode::class)->active()) {
            return $this->resolveFindByPath($tenant, $normalizedPath, $user);
        }

        $tenantId = $tenant->getKey();
        $key = CacheKeys::content($tenantId, $normalizedPath);
        $cache = app(TenantCache::class);

        // Taken before the database is read: a write that commits while this request is
        // still resolving moves the generation on, and what is stored below then lands
        // where nobody reads it ({@see TenantCache}).
        $generation = $cache->generation($tenantId);

        // `false` = not cached; MISS = a cached 404 (negative hit); an attribute
        // array (ModelCache) = a real hit. Payloads stay scalar so L13's
        // `serializable_classes = false` cache stores can round-trip them.
        $cached = $cache->get($tenantId, $key, false);

        if ($cached === self::MISS) {
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

        // A hit under another spelling is only ever answered with a redirect; caching it
        // would add a key per spelling a visitor happens to type, for a page that answers
        // under its own address anyway.
        if ($content !== null && ! $this->isExactMatch($content, $normalizedPath)) {
            return $content;
        }

        if ($content !== null) {
            // Cached until the page's own publish_until (null = until the tenant's next
            // write) — the status is time-derived, so a write-invalidated cache would keep
            // serving the page past its expiry. Scheduled pages heal through the short
            // 404-miss TTL below. withDraft: false — this cache serves the live site,
            // which never overlays drafts.
            $cache->put($tenantId, $key, ModelCache::pack($content, withDraft: false), $content->publish_until, $generation);

            return $content;
        }

        // Cache 404s only BRIEFLY: this absorbs bursts of bots hammering the same dead URL
        // without letting an attacker enumerating distinct paths grow the cache store
        // without bound (as rememberForever did).
        $cache->put($tenantId, $key, self::MISS, now()->addSeconds(self::MISS_CACHE_TTL), $generation);

        return null;
    }

    /** Seconds a negative (404) path lookup is cached — long enough to shed bot floods, short enough to bound growth. */
    protected const MISS_CACHE_TTL = 60;

    /**
     * What a cached 404 holds. Not null: the cache reads a stored null as "nothing stored",
     * which sent every repeated dead URL back to the database.
     */
    protected const MISS = 'not-found';

    /**
     * The visible page stored at this path. Both spellings of a mixed-case path are asked in
     * one indexed query, the exact one first. A miss asks once more by lower-cased path: a
     * case-insensitive collation (MySQL, MariaDB) has answered every spelling already, a
     * case-sensitive one (SQLite) only finds a page stored with capitals — a legacy row, an
     * import — that way.
     */
    protected function resolveFindByPath(Tenant $tenant, string $normalizedPath, ?Authenticatable $user = null): ?Content
    {
        $lowercasePath = mb_strtolower($normalizedPath);

        $candidates = Cms::contentModel()::query()
            ->visibleTo($tenant, $user)
            ->whereIn('path', array_unique([$normalizedPath, $lowercasePath]))
            ->get();

        if ($candidates->isEmpty()) {
            $query = Cms::contentModel()::query()->visibleTo($tenant, $user);

            $candidates = $query
                ->whereRaw('lower('.$query->getQuery()->getGrammar()->wrap('path').') = ?', [$lowercasePath])
                ->get();
        }

        // A case-insensitive collation returns every spelling for either value.
        foreach ($candidates->sortByDesc(fn (Content $content): bool => $content->path === $normalizedPath) as $content) {
            $content->setRelation('tenant', $tenant);

            // A non-routable blueprint has no URL: ignore a stale/leftover `path`
            // column (e.g. a type that became non-routable after rows were saved)
            // so it cannot be served via the catch-all route.
            if ($this->isRoutable($content, $tenant)) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Whether a found page lives at exactly this path, not at another spelling of it.
     */
    protected function isExactMatch(Content $content, string $normalizedPath): bool
    {
        return $content->path === $normalizedPath || $content->resolvedPath() === $normalizedPath;
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
        // Same gate as findByPath(): without an active preview the user-aware
        // result equals the guest result — serve the warm cache.
        if ($user !== null && app(PreviewMode::class)->active()) {
            return $this->resolveSections($tenant, $user);
        }

        // Cached as a list of attribute arrays (ModelCache) — see findByPath().
        // TTL until the tenant's next publishing transition: a scheduled section
        // must appear (and an expiring one vanish) on time, not on the next write.
        // bypass(): user-less callers (e.g. the sitemap) can run during a
        // preview request; packing overlaid models would serve DRAFT sections
        // to every guest until the next invalidation.
        $sections = ModelCache::unpackMany(Cms::contentModel(), app(TenantCache::class)->remember(
            $tenant->getKey(),
            CacheKeys::sections($tenant->getKey()),
            // Closure TTL: only evaluated when the cache actually stores.
            fn (): ?\Carbon\CarbonInterface => PublishingTransitions::nextFor($tenant),
            fn (): array => app(PreviewMode::class)->bypass(
                fn (): array => ModelCache::packMany($this->resolveSections($tenant), withDraft: false),
            ),
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

        // Tenant-scoped, and it ends at a cycle instead of looping on it ({@see ContentTree::ancestorsOf()}).
        foreach (app(ContentTree::class)->ancestorsOf($content) as $ancestor) {
            if ($this->isOnepagerShellRoot($ancestor, $tenant)) {
                return $ancestor;
            }
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

        $pathSegment = $this->normalizer->lastSegment($resolvedPath);

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
