<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ContentEditPage;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Content\FrontendUrl;
use Mmoollllee\Cms\Support\Content\PathConflicts;
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
 * On top of that it supplies the frontend link every "open this record in the frontend"
 * affordance uses ({@see frontendPath()} / {@see getFrontendUrl()}): "Vorschau", the
 * table's "Öffnen" action and the topbar button.
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

            // The (tenant_id, path) index is the last line of defence, and on its own it
            // answers with an uncaught UniqueConstraintViolationException — a 500 with the
            // edit lost. Ask here instead, before anything is written: every writer passes
            // through this hook, not just the panel form.
            static::failOnPathConflict($content);

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

            // All or nothing. The saving guard above rejects a move whose subtree does not
            // fit, but a row written between that check and this cascade would still throw
            // here — and half a subtree left at the new prefix has to be repaired by hand.
            // The parent's own UPDATE joins this transaction wherever the caller opened
            // one; the panel does, via BasePanelProvider's databaseTransactions().
            DB::transaction(function () use ($content): void {
                Cms::contentModel()::query()
                    ->where('parent_id', $content->getKey())
                    ->get()
                    ->each(fn (Model $child) => $child->save());
            });
        });
    }

    /**
     * Refuse a save whose stored path — or the path any descendant would be cascaded onto
     * — already belongs to another record of the same tenant.
     *
     * @throws ValidationException
     */
    protected static function failOnPathConflict(Model $content): void
    {
        $conflict = app(PathConflicts::class)->firstConflict($content);

        if ($conflict === null) {
            return;
        }

        $owner = $conflict['owner']->getAttribute('title');

        throw ValidationException::withMessages([
            'path' => $conflict['record'] === $content
                ? sprintf('Der Pfad „%s“ ist bereits von „%s“ belegt.', $conflict['path'], $owner)
                : sprintf(
                    '„%s“ würde dadurch auf „%s“ verschoben, und diesen Pfad belegt bereits „%s“.',
                    $conflict['record']->getAttribute('title'),
                    $conflict['path'],
                    $owner,
                ),
        ]);
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
     * The frontend path this record is reachable at: its own URL path, or — for
     * non-routable types (sections, embedded types) — the parent page that renders
     * it. Null when neither has a path.
     *
     * Shared by the "Vorschau" action ({@see ContentEditPage})
     * and the topbar "Öffnen" button, so both open the same page for a record.
     */
    public function frontendPath(): ?string
    {
        return $this->resolvedPath() ?? $this->parent?->resolvedPath();
    }

    /**
     * Absolute public URL of {@see frontendPath()} — null for records without a
     * frontend page (and for apps without a `content.show` route).
     */
    public function getFrontendUrl(): ?string
    {
        return FrontendUrl::forPath($this->frontendPath());
    }

    /**
     * Populate the `tenant` relation (needed for the blueprint/site_key lookup), reusing
     * the request's current tenant to avoid a query when it matches.
     *
     * Deliberately independent of hook order: a record still being created can reach this
     * without a tenant_id — and with the relation already cached as null from an earlier
     * read — and without a site_key the blueprint lookup fails silently, which used to
     * leave the path at whatever the form sent instead of the parent-driven one. The
     * request's tenant is the one the record is about to be assigned to anyway
     * ({@see AssignsCurrentTenant}).
     */
    protected static function ensureTenantLoaded(Model $content): void
    {
        // A relation that is loaded AND populated is taken as given: resolvedPath() runs
        // per row in the resolver, the navigation and the sitemap, and stays query-free
        // only because callers pre-seed it. Only a relation cached as NULL — what a record
        // read before it had a tenant leaves behind — falls through.
        if ($content->relationLoaded('tenant') && $content->getRelation('tenant') !== null) {
            return;
        }

        $current = app(CurrentTenant::class)->get();

        if ($content->tenant_id === null) {
            // A record still being created reaches this before its tenant is assigned, and
            // without a site_key the blueprint lookup fails silently — which used to leave
            // the path at whatever the form sent instead of the parent-driven one. The
            // request's tenant is the one it is about to be assigned to anyway
            // ({@see AssignsCurrentTenant}).
            if ($current !== null) {
                // Assign the key as well, not just the relation: the conflict guard below
                // scopes its lookup by tenant_id, and a null one silently matches no owner
                // at all. Leaving it to AssignsCurrentTenant would make that guard depend
                // on which of the two `saving` listeners a model's trait order boots first.
                $content->setAttribute('tenant_id', $current->getKey());
                $content->setRelation('tenant', $current);
            }

            return;
        }

        // Cast both sides: a tenant_id that arrived as a string from form state would
        // otherwise miss the match and query the tenant back on every single call.
        $tenant = $current !== null && (int) $current->getKey() === (int) $content->tenant_id
            ? $current
            : Cms::tenantModel()::query()->find($content->tenant_id);

        if ($tenant !== null) {
            $content->setRelation('tenant', $tenant);
        }
    }
}
