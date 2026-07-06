<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Support\Str;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;

/**
 * Generates the URL path for a Content record based on its blueprint.
 *
 * Called automatically during Content::saving() to keep the `path` column
 * in sync. Non-routable blueprints return null (content has no URL).
 *
 * Path generation priority:
 * 1. Parent-driven path (typical CMS nesting): for routable types WITHOUT a
 *    urlPathPrefix that have a parent, the path is parent path + own last
 *    segment — the parent defines the prefix, the record only owns its slug.
 * 2. Blueprint's generatePath() logic (urlPathPrefix types keep type-based paths)
 * 3. Content's existing path (if already set)
 * 4. Auto-generated from slug (or Str::slug(title))
 *
 * @see ConfiguredContentBlueprint::generatePath() — blueprint-level logic
 */
class PathGenerator
{
    public function __construct(
        protected ContentBlueprintRegistry $blueprints,
        protected PathNormalizer $normalizer,
    ) {}

    /**
     * Generate the URL path for the given content.
     *
     * Returns null for non-routable content types.
     */
    public function generate(Content $content): ?string
    {
        $blueprint = $this->blueprints->find(
            $content->content_type,
            $content->tenant?->site_key,
        );

        if ($blueprint === null) {
            return $this->normalize($content->path);
        }

        if ($blueprint->isRoutable() === false) {
            return null;
        }

        // Parent-driven nesting: the parent's path is the prefix, the record only
        // owns its last segment. Types with an explicit urlPathPrefix keep their
        // type-based paths (the prefix wins over the hierarchy).
        if ($blueprint->urlPathPrefix() === null && ($parentPath = $this->parentPath($content)) !== null) {
            $segment = $this->ownSegment($content);

            if (filled($segment)) {
                return $this->normalize(rtrim($parentPath, '/').'/'.$segment);
            }
        }

        // Path already set by the form — normalize and return
        if (filled($content->path)) {
            return $this->normalize($content->path);
        }

        // Fallback: generate from slug/title (for programmatic creation, seeders, etc.)
        if (blank($content->slug)) {
            $content->slug = Str::slug($content->title);
        }

        return $this->normalize($blueprint->generatePath($content));
    }

    /**
     * The resolved path of the content's parent, or null when there is no parent
     * (or the parent itself has no URL — then the record stays top-level).
     */
    protected function parentPath(Content $content): ?string
    {
        if ($content->parent_id === null) {
            return null;
        }

        $parent = $content->relationLoaded('parent') ? $content->parent : $content->parent()->first();

        if (! $parent instanceof Content) {
            return null;
        }

        $path = $parent->resolvedPath();

        return filled($path) ? $path : null;
    }

    /**
     * The record's own URL segment: the last segment of the typed path, falling
     * back to the slug, falling back to the slugified title.
     */
    protected function ownSegment(Content $content): ?string
    {
        if (filled($content->path)) {
            return Str::afterLast(trim($content->path, '/'), '/');
        }

        if (filled($content->slug)) {
            return trim($content->slug, '/');
        }

        return filled($content->title) ? Str::slug($content->title) : null;
    }

    public function normalize(?string $path): ?string
    {
        return $this->normalizer->normalizeOrNull($path);
    }
}
