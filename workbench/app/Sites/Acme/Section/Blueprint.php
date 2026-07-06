<?php

namespace Workbench\App\Sites\Acme\Section;

use Mmoollllee\Cms\Sites\Default\Section\Blueprint as DefaultSectionBlueprint;

/**
 * Per-site override of the default.section blueprint (same key → replaces the
 * package one for this site): the Acme onepager composes its frontend from
 * sections, so editors may create them via the Seiten-Typ select.
 */
class Blueprint extends DefaultSectionBlueprint
{
    protected bool $offeredInTypeSelect = true;
}
