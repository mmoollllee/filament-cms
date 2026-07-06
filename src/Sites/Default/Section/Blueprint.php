<?php

namespace Mmoollllee\Cms\Sites\Default\Section;

use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;

class Blueprint extends ConfiguredContentBlueprint
{
    protected string $key = 'default.section';

    protected string $label = 'Sektion';

    protected string $defaultTemplate = 'content.page';

    protected bool $supportsTeasers = true;

    // Sections are opt-in per site: an onepager site re-declares this blueprint
    // (same key, subclass) with the flag on — pages-only sites never see the
    // Seiten-Typ choice.
    protected bool $offeredInTypeSelect = false;
}
