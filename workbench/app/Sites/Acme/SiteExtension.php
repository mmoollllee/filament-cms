<?php

namespace Workbench\App\Sites\Acme;

use Mmoollllee\Cms\Contracts\SiteExtension as SiteExtensionContract;
use Mmoollllee\Cms\Sites\Concerns\DiscoversSiteBlueprints;
use Mmoollllee\Cms\Sites\Concerns\DiscoversSiteResources;

/**
 * Site extension for the demo's second tenant (the onepager, localhost):
 * demonstrates OVERRIDING a default blueprint per site — its Section blueprint
 * re-declares `default.section` with the Seiten-Typ flag switched on, so this
 * site (and only this site) can create sections via the catch-all form.
 */
class SiteExtension implements SiteExtensionContract
{
    use DiscoversSiteBlueprints;
    use DiscoversSiteResources;

    public function siteKey(): string
    {
        return 'acme';
    }
}
