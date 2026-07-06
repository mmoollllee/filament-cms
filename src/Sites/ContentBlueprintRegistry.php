<?php

namespace Mmoollllee\Cms\Sites;

use Mmoollllee\Cms\Contracts\ContentBlueprint;

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
                $blueprints[$blueprint->key()] = $blueprint;
            }
        }

        return array_values($blueprints);
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
