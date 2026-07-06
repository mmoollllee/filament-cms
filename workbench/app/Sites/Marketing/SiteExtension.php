<?php

namespace Workbench\App\Sites\Marketing;

use Mmoollllee\Cms\Contracts\SiteExtension as SiteExtensionContract;
use Mmoollllee\Cms\Sites\Concerns\DiscoversSiteBlueprints;
use Mmoollllee\Cms\Sites\Concerns\DiscoversSiteResources;

class SiteExtension implements SiteExtensionContract
{
    use DiscoversSiteBlueprints;
    use DiscoversSiteResources;

    public function siteKey(): string
    {
        return 'marketing';
    }
}
