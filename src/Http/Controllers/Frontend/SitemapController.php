<?php

namespace Mmoollllee\Cms\Http\Controllers\Frontend;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Content\ContentResolver;
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

        $xml = Cache::rememberForever(
            CacheKeys::sitemap($tenant->getKey()),
            // bypass(): generated during a preview request, the forever-cached
            // XML (and the sections cache warmed inside) would otherwise carry
            // DRAFT paths/titles to guests and crawlers.
            fn (): string => app(PreviewMode::class)->bypass(fn (): string => $this->generateSitemapXml($tenant)),
        );

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }

    protected function generateSitemapXml(Tenant $tenant): string
    {
        $urls = collect();

        // Homepage
        $urls->push([
            'loc' => url('/'),
            'priority' => '1.0',
            'changefreq' => 'weekly',
        ]);

        // Onepager sections
        foreach ($this->contentResolver->sections($tenant) as $section) {
            $path = $section->resolvedPath();

            if ($path === null || $path === '/') {
                continue;
            }

            $urls->push([
                'loc' => url($path),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ]);
        }

        // Every other routable content type the tenant's site registered (pages,
        // articles, …). The type set comes from the blueprints, so the engine ships
        // no content taxonomy of its own; onepager sections are already emitted above.
        foreach ($this->standaloneContent($tenant) as $page) {
            $path = $page->resolvedPath();

            if ($path === null || $path === '/') {
                continue;
            }

            $urls->push([
                'loc' => url($path),
                'priority' => $this->standalonePriority($page),
                'changefreq' => $this->standaloneChangefreq($page),
            ]);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url>'."\n";
            $xml .= '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1).'</loc>'."\n";
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
     * @return iterable<int, \Mmoollllee\Cms\Contracts\Content>
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
}
