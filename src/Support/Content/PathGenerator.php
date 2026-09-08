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
 * The one invariant everything here rests on: A DERIVED PATH NEVER READS THE STORED
 * PATH AS A WHOLE — only its last segment. A record owns exactly one segment; who owns
 * the rest is decided in this order:
 *  1. the blueprint, when it declares a urlPathPrefix (the prefix wins over the tree)
 *  2. the parent, for a type without a prefix that has one
 *  3. nobody — then, and only then, the stored path stands as authored
 *  4. nothing to compose from yet: the blueprint's own
 *     {@see \Mmoollllee\Cms\Contracts\ContentBlueprint::generatePath()}
 *
 * Because lastSegment(compose(owner, segment)) === segment, steps 1 and 2 are idempotent
 * by construction: re-running them on their own output returns it unchanged, and running
 * them on a path that drifted (a form that composed by hand, a legacy row, an import)
 * returns the record to where its type and its tree say it belongs. That is what keeps a
 * wrong value from sticking — the class of bug this file used to be the origin of.
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

        $segment = $this->ownSegment($content);

        if (filled($segment)) {
            // Type-driven: the prefix owns everything but the last segment, wherever the
            // record sits in the tree. Composed rather than trusted, so a record created
            // in the panel — which always arrives with a path, the field being required —
            // lands under its prefix like a programmatically created one.
            if (($prefix = $blueprint->urlPathPrefix()) !== null) {
                return $this->normalize(rtrim($prefix, '/').'/'.$segment);
            }

            // Parent-driven nesting: the parent's path is the prefix, the record only
            // owns its last segment.
            if (($parentPath = $this->parentPath($content)) !== null) {
                return $this->normalize(rtrim($parentPath, '/').'/'.$segment);
            }
        }

        // Authored: no prefix and no parent owns any of this, so the stored path stands —
        // in full, including the multi-segment path an orphaned record keeps.
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
