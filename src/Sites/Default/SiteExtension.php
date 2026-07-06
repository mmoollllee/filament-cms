<?php

namespace Mmoollllee\Cms\Sites\Default;

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\SiteExtension as SiteExtensionContract;
use Mmoollllee\Cms\Sites\Concerns\DiscoversSiteBlueprints;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;

/**
 * The always-present "default" site extension shipped by the package.
 *
 * Provides the universal `default.page` / `default.section` content types and points
 * at the app's catch-all content resource (Cms::contentResource()) so those types
 * are editable in the panel. The {@see SiteExtensionRegistry}
 * always loads this; an app may still ship its own `App\Sites\Default\SiteExtension`,
 * which overrides this one by site key.
 */
class SiteExtension implements SiteExtensionContract
{
    use DiscoversSiteBlueprints;

    public function siteKey(): string
    {
        return 'default';
    }

    public function resources(): array
    {
        return [Cms::contentResource()];
    }
}
