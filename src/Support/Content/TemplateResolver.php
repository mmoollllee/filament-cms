<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;

/**
 * Maps a Content record to its Blade template view name.
 *
 * Resolution order:
 * 1. Content's own `template` field (explicit override set in admin)
 * 2. The blueprint's defaultTemplate()
 * 3. Fallback: 'content.page'
 *
 * For each candidate, tries the site-specific view first ("{site_key}.{template}"),
 * then falls back to the shared content directory ("{template}").
 *
 * Called after ContentResolver has found the Content record. The resolved view
 * name is passed to the layout as `$contentView` / `$currentContentView`.
 */
class TemplateResolver
{
    public function __construct(
        protected ContentBlueprintRegistry $blueprints,
        protected ViewFactory $views,
    ) {}

    /**
     * Resolve the Blade view name for the given content within a tenant context.
     */
    public function resolve(Content $content, Tenant $tenant): string
    {
        $blueprint = $this->blueprints->find($content->content_type, $tenant->site_key);
        $template = $content->template ?: $blueprint?->defaultTemplate() ?: 'content.page';

        $candidates = [
            "{$tenant->site_key}.{$template}",
            $template,
        ];

        foreach ($candidates as $candidate) {
            if ($this->views->exists($candidate)) {
                return $candidate;
            }
        }

        return $candidates[array_key_last($candidates)];
    }
}
