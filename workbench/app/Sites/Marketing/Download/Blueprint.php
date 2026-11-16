<?php

namespace Workbench\App\Sites\Marketing\Download;

use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;

/**
 * Demo child record without a page of its own: a download that only shows
 * where a section lists its children. Exercises the parent select's narrowing
 * to the sections whose template lists the type (ContentParentListingScopeTest).
 */
class Blueprint extends ConfiguredContentBlueprint
{
    protected string $key = 'marketing.download';

    protected string $label = 'Download';

    protected ?string $pluralLabel = 'Downloads';

    protected string $defaultTemplate = 'content.page';

    protected bool $isRoutable = false;

    protected bool $hasBuilder = false;

    protected array $allowedParentTypes = ['default.section'];
}
