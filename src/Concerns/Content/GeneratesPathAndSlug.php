<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Content\PathGenerator;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Keeps a Content model's `path` + `slug` in sync with its blueprint's `isRoutable()`,
 * on both the write side (the saving hook) and the read side ({@see resolvedPath()}):
 *
 * - Routable types: `path` is generated (via {@see PathGenerator}) and the `slug` is
 *   derived from the path's last segment (path is the source of truth).
 * - Non-routable types: `path` stays null (no URL), but a tenant-unique `slug` is kept
 *   (from the form, or auto-derived from the title for programmatic creation).
 *
 * resolvedPath() delegates to {@see PathGenerator} so routability is the single source of
 * truth: the normalized stored path for routable types, null for non-routable ones —
 * never a stale leftover path on a non-routable record.
 *
 * The host model needs `title`, `slug`, `path`, `content_type` columns and a `tenant`
 * relation (a {@see Content}).
 */
trait GeneratesPathAndSlug
{
    protected static function bootGeneratesPathAndSlug(): void
    {
        static::saving(function (Model $content): void {
            // Load the tenant relation so the blueprint lookup can scope by site_key.
            static::ensureTenantLoaded($content);

            $blueprint = app(ContentBlueprintRegistry::class)->find(
                $content->content_type,
                $content->tenant?->site_key,
            );

            // Non-routable types have no path, but keep a tenant-unique slug.
            if ($blueprint !== null && $blueprint->isRoutable() === false) {
                $content->path = null;

                if (blank($content->slug) && filled($content->title)) {
                    $content->slug = Str::slug($content->title);
                }

                return;
            }

            // Auto-generate a slug from the title for programmatic creation (seeders, factories).
            if (blank($content->slug) && blank($content->path) && filled($content->title)) {
                $content->slug = Str::slug($content->title);
            }

            $content->path = app(PathGenerator::class)->generate($content);

            // Derive the slug from the path's last segment (path is the source of truth).
            if (filled($content->path) && $content->path !== '/') {
                $lastSegment = Str::afterLast(trim($content->path, '/'), '/');

                if (filled($lastSegment)) {
                    $content->slug = $lastSegment;
                }
            }
        });

        // Renaming/moving a page moves its subtree: children re-save, which re-runs
        // the same parent-driven path composition per child (recursively down the
        // tree). Old URLs fall through to the redirect/404 pipeline, which logs and
        // auto-resolves them.
        static::saved(function (Model $content): void {
            if (! $content->wasChanged('path')) {
                return;
            }

            Cms::contentModel()::query()
                ->where('parent_id', $content->getKey())
                ->get()
                ->each(fn (Model $child) => $child->save());
        });
    }

    /**
     * The content's URL path, or null when its blueprint is non-routable. Delegates to
     * {@see PathGenerator} (the single source of truth) rather than trusting a possibly
     * stale `path` column.
     */
    public function resolvedPath(): ?string
    {
        $content = clone $this;

        static::ensureTenantLoaded($content);

        return app(PathGenerator::class)->generate($content);
    }

    /**
     * Populate the `tenant` relation (needed for the blueprint/site_key lookup) when it
     * isn't already loaded, reusing the request's current tenant to avoid a query when it
     * matches.
     */
    protected static function ensureTenantLoaded(Model $content): void
    {
        if ($content->relationLoaded('tenant') || $content->tenant_id === null) {
            return;
        }

        $current = app(CurrentTenant::class)->get();

        $tenant = $current?->getKey() === $content->tenant_id
            ? $current
            : Cms::tenantModel()::query()->find($content->tenant_id);

        if ($tenant !== null) {
            $content->setRelation('tenant', $tenant);
        }
    }
}
