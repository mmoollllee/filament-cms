<?php

namespace Mmoollllee\Cms\Sites\Default\Page;

use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;

class Blueprint extends ConfiguredContentBlueprint
{
    protected string $key = 'default.page';

    protected string $label = 'Seite';

    protected string $defaultTemplate = 'content.page';

    protected bool $participatesInOnepager = false;

    // Pages nest under pages: enables the "Übergeordnete Seite" select, the
    // breadcrumb trail and parent-driven paths (/howto → /howto/custom-blocks).
    protected array $allowedParentTypes = ['default.page'];
}
