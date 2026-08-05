<?php

namespace Mmoollllee\Cms\Http\Controllers\Frontend;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Content\PublishingTransitions;
use Mmoollllee\Cms\Support\Preview\PreviewMode;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Generates an XML sitemap for the current tenant.
 *
 * Includes all visible, routable content for anonymous visitors:
 * onepager sections and standalone pages.
 */
class SitemapController
{
    public function __construct(
        protected CurrentTenant $currentTenant,
        protected ContentResolver $contentResolver,
        protected ContentBlueprintRegistry $blueprints,
    ) {}

    public function __invoke(Request $request): Response
    {
        $tenant = $this->currentTenant->get();

        abort_if($tenant === null, 404);

        $xml = Cache::remember(
            CacheKeys::sitemap($tenant->getKey()),
            // TTL until the next publishing transition (null = forever): the
            // sitemap must gain scheduled pages and drop expiring ones on time,
            // not on the next content write. Closure — only evaluated on store.
            fn (): ?\Carbon\CarbonInterface => PublishingTransitions::nextFor($tenant),
            // bypass(): generated during a preview request, the cached XML (and
            // the sections cache warmed inside) would otherwise carry DRAFT
            // paths/titles to guests and crawlers.
            fn (): string => app(PreviewMode::class)->bypass(fn (): string => $this->generateSitemapXml($tenant)),
        );

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }

    protected function generateSitemapXml(Tenant $tenant): string
    {
        $sections = collect($this->contentResolver->sections($tenant));
        $standalone = collect($this->standaloneContent($tenant));

        $urls = collect();

        // Homepage. It has no content row of its own, so it inherits the freshest edit
        // anywhere on the site — that is what actually changes what "/" shows.
        $urls->push([
            'loc' => url('/'),
            'lastmod' => $this->latestChangeAcross($sections->concat($standalone)),
            'priority' => '1.0',
            'changefreq' => 'weekly',
        ]);

        // Onepager sections
        foreach ($sections as $section) {
            $path = $section->resolvedPath();

            if ($path === null || $path === '/') {
                continue;
            }

            $urls->push([
                'loc' => url($path),
                'lastmod' => $this->lastModifiedFor($section),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ]);
        }

        // Every other routable content type the tenant's site registered (pages,
        // articles, …). The type set comes from the blueprints, so the engine ships
        // no content taxonomy of its own; onepager sections are already emitted above.
        foreach ($standalone as $page) {
            $path = $page->resolvedPath();

            if ($path === null || $path === '/') {
                continue;
            }

            $urls->push([
                'loc' => url($path),
                'lastmod' => $this->lastModifiedFor($page),
                'priority' => $this->standalonePriority($page),
                'changefreq' => $this->standaloneChangefreq($page),
            ]);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url>'."\n";
            $xml .= '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1).'</loc>'."\n";

            // Optional per the sitemap spec: omitting beats emitting a guess.
            if (($url['lastmod'] ?? null) !== null) {
                $xml .= '    <lastmod>'.$url['lastmod'].'</lastmod>'."\n";
            }

            $xml .= '    <changefreq>'.$url['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$url['priority'].'</priority>'."\n";
            $xml .= '  </url>'."\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Visible content of every registered content type that has its own URL and does
     * not participate in the onepager (those are emitted as sections). The type set is
     * derived from the tenant's blueprints, so a consuming app's own content types are
     * included without the engine hardcoding any names.
     *
     * @return iterable<int, Content>
     */
    protected function standaloneContent(Tenant $tenant): iterable
    {
        $types = collect($this->blueprints->forSite($tenant->site_key))
            ->filter(fn (ContentBlueprint $blueprint): bool => $blueprint->isRoutable() && ! $blueprint->participatesInOnepager())
            ->map(fn (ContentBlueprint $blueprint): string => $blueprint->key())
            ->values()
            ->all();

        if ($types === []) {
            return [];
        }

        return Cms::contentModel()::query()
            ->visibleTo($tenant)
            ->ofType($types)
            ->get();
    }

    /**
     * Crawl-priority for a standalone content URL — override in the app to
     * differentiate per content type (e.g. job postings higher than legal pages).
     */
    protected function standalonePriority(Content $content): string
    {
        return '0.5';
    }

    /** Crawl-changefreq counterpart of {@see self::standalonePriority()}. */
    protected function standaloneChangefreq(Content $content): string
    {
        return 'weekly';
    }

    /**
     * W3C-datetime `<lastmod>` for a content URL.
     *
     * Crawlers use it to decide what is worth re-fetching; without it every URL looks
     * equally stale and an edit is picked up on the crawler's own schedule. Override in
     * the app when a content type tracks its editorial date somewhere other than
     * `updated_at` (a published_at column, a payload field, …). Returning null omits the
     * element, which the spec allows.
     */
    protected function lastModifiedFor(Content $content): ?string
    {
        return $content->updated_at?->toAtomString();
    }

    /**
     * The freshest {@see self::lastModifiedFor()} across a set of content rows, used for
     * URLs that aggregate content instead of having a row of their own.
     *
     * @param  Collection<int, Content>  $contents
     */
    protected function latestChangeAcross(Collection $contents): ?string
    {
        return $contents
            ->map(fn (Content $content): ?string => $this->lastModifiedFor($content))
            ->filter()
            ->sortDesc()
            ->first();
    }
}
