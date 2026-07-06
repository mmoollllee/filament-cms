<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;

/**
 * Resolves the model's `layout_preset_ids` (array cast) to a CSS class string
 * via the request-scoped {@see LayoutPresetResolver} cache. Used by the frontend
 * controllers/views for the page-level layout classes.
 */
trait ResolvesLayoutPresets
{
    public function resolvedLayoutPreset(): string
    {
        $ids = array_map('intval', array_filter((array) ($this->layout_preset_ids ?? [])));

        return app(LayoutPresetResolver::class)->resolve($ids);
    }
}
