<?php

namespace Workbench\App\Sites\Marketing\Service;

use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;

class Blueprint extends ConfiguredContentBlueprint
{
    protected string $key = 'marketing.service';

    protected string $label = 'Service';

    protected string $defaultTemplate = 'content.service';

    protected bool $isRoutable = false;

    protected ?string $navigationLabel = 'Services';
}
