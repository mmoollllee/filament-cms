<?php

namespace Workbench\App\Sites\Marketing\Guide;

use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;

/**
 * Demo content type with a urlPathPrefix: its records live under the prefix
 * regardless of where they sit in the tree, which is the branch of
 * PathGenerator::generate() that skips parent-driven nesting.
 *
 * It declares no allowedParentTypes, and may not: a type takes its address from the
 * prefix or from the tree, never both ({@see ConfiguredContentBlueprint::urlPathPrefix()}).
 */
class Blueprint extends ConfiguredContentBlueprint
{
    protected string $key = 'marketing.guide';

    protected string $label = 'Ratgeber';

    protected string $defaultTemplate = 'content.page';

    protected ?string $urlPathPrefix = '/ratgeber/';
}
