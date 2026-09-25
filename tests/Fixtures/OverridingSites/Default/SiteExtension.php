<?php

namespace Mmoollllee\Cms\Tests\Fixtures\OverridingSites\Default;

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\SiteExtension as SiteExtensionContract;

/**
 * An app's own default extension that implements the contract rather than
 * extending the package class — it drops what Cms::enableNotices() adds there.
 */
class SiteExtension implements SiteExtensionContract
{
    public function siteKey(): string
    {
        return 'default';
    }

    public function blueprints(): array
    {
        return [];
    }

    public function resources(): array
    {
        return [Cms::contentResource()];
    }
}
