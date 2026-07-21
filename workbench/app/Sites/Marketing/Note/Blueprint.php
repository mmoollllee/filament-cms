<?php

namespace Workbench\App\Sites\Marketing\Note;

use Mmoollllee\Cms\Sites\ConfiguredContentBlueprint;

/**
 * Demo non-routable content type: no URL/path, but a tenant-unique slug
 * (exercises GeneratesPathAndSlug + the slug-only form field).
 */
class Blueprint extends ConfiguredContentBlueprint
{
    protected string $key = 'marketing.note';

    protected string $label = 'Note';

    protected string $defaultTemplate = 'content.page';

    protected bool $isRoutable = false;

    protected bool $hasBuilder = false;

    // Demo for the opt-in raw payload editor (and its delete-persistence
    // contract, pinned by ContentRawPayloadEditorTest).
    protected bool $showsPayloadEditor = true;
}
