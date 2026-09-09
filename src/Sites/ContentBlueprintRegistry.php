<?php

namespace Mmoollllee\Cms\Sites;

use LogicException;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Support\Content\PathGenerator;

/**
 * Aggregates ContentBlueprint definitions from all active site extensions.
 *
 * forSite($siteKey) collects blueprints from the 'default' extension and the
 * tenant-specific extension (if any) — keyed by blueprint key, so a site
 * extension can OVERRIDE a default blueprint by re-declaring its key (e.g. a
 * `default.section` subclass that flips a flag for that site only).
 * find($key, $siteKey) looks up a single blueprint by its dot-notated key,
 * with fallback to the default extension.
 *
 * @see SiteExtensionRegistry — provides the extension list
 */
class ContentBlueprintRegistry
{
    /** @var array<string, array<int, ContentBlueprint>> Request-scoped memo (the registry is a singleton). */
    protected array $forSiteCache = [];

    public function __construct(
        protected SiteExtensionRegistry $siteExtensionRegistry,
    ) {}

    /**
     * @return array<int, ContentBlueprint>
     */
    public function forSite(?string $siteKey = null): array
    {
        // Memoized: forSite()/find() run in frontend hot paths (per-row in section/candidate
        // loops, and several times per content via PathGenerator/isRoutable/onepager checks).
        // Blueprints are static per request, so re-aggregating the extensions' sets each call
        // is pure waste.
        return $this->forSiteCache[$siteKey ?? ''] ??= $this->buildForSite($siteKey);
    }

    /**
     * @return array<int, ContentBlueprint>
     */
    protected function buildForSite(?string $siteKey): array
    {
        $blueprints = [];

        // Extensions load default-first; keying by blueprint key lets the
        // site-specific extension override a default blueprint (last wins).
        foreach ($this->siteExtensionRegistry->forSite($siteKey) as $extension) {
            foreach ($extension->blueprints() as $blueprint) {
                $this->assertOneAddressRule($blueprint);

                $blueprints[$blueprint->key()] = $blueprint;
            }
        }

        return array_values($blueprints);
    }

    /**
     * A type derives its URL EITHER from its own namespace OR from the tree — never both.
     *
     * {@see PathGenerator} lets the prefix win, so a
     * type declaring both hands the editor a parent Select that moves the breadcrumb and
     * the listing but has no effect whatsoever on the URL. That contradiction is silent,
     * and it is a declaration mistake rather than a runtime state — so it is refused.
     *
     * Asked HERE, where blueprints are aggregated (memoized per site key, and the funnel
     * every blueprint passes through), rather than from the accessors: urlPathPrefix() is
     * called per row by the frontend resolver, the sitemap, the navigation and the redirect
     * map, so asserting there would turn one typo into a site-wide 500 on whichever request
     * touched it first. It also covers a class implementing the interface directly, which
     * an assert on {@see ConfiguredContentBlueprint} cannot.
     *
     * @throws LogicException
     */
    protected function assertOneAddressRule(ContentBlueprint $blueprint): void
    {
        if ($blueprint->urlPathPrefix() !== null && $blueprint->allowedParentTypes() !== []) {
            throw new LogicException(sprintf(
                'Content blueprint [%s] declares urlPathPrefix AND allowedParentTypes. A type '
                .'takes its address from one of the two: the prefix owns the namespace wherever '
                .'the record sits, or the parent does. Drop whichever the URL should not follow.',
                $blueprint->key(),
            ));
        }
    }

    public function find(string $key, ?string $siteKey = null): ?ContentBlueprint
    {
        foreach ($this->forSite($siteKey) as $blueprint) {
            if ($blueprint->key() === $key) {
                return $blueprint;
            }
        }

        if ($siteKey !== null) {
            return $this->find($key);
        }

        return null;
    }

    /**
     * Human label for a content type, falling back to the raw key — the single
     * lookup shared by the content tables and the recent-versions widget.
     */
    public function labelFor(string $key, ?string $siteKey = null): string
    {
        return $this->find($key, $siteKey)?->label() ?? $key;
    }

    /**
     * @return array<string, string>
     */
    public function options(?string $siteKey = null): array
    {
        $options = [];

        foreach ($this->forSite($siteKey) as $blueprint) {
            $options[$blueprint->key()] = $blueprint->label();
        }

        return $options;
    }
}
