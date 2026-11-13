<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ContentEditPage;
use Mmoollllee\Cms\Rules\ValidParent;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Content\ContentTree;
use Mmoollllee\Cms\Support\Content\FrontendUrl;
use Mmoollllee\Cms\Support\Content\PathConflicts;
use Mmoollllee\Cms\Support\Content\PathGenerator;
use Mmoollllee\Cms\Support\Routing\ContentRenameRedirects;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Keeps a Content model's `path` + `slug` in sync with its blueprint's `isRoutable()`:
 *
 * - Routable types: `path` is generated (via {@see PathGenerator}) and the `slug` is
 *   derived from the path's last segment (path is the source of truth).
 * - Non-routable types: `path` stays null (no URL), but a tenant-unique `slug` is kept
 *   (from the form, or auto-derived from the title for programmatic creation).
 *
 * The stored `path` is THE address — computed on write, read as it is. The saving hook
 * stores the generator's answer on every save, and a save that moves a record carries its
 * subtree along inside the same transaction ({@see save()}), so the column is current by
 * construction. {@see resolvedPath()} reads it back (null for non-routable types), and
 * {@see generatedPath()} answers what a save WOULD store.
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

            // Before anything below composes against the parent: a parent of another
            // tenant, one below the record itself or of a type the blueprint rules out
            // never reaches the database, whoever writes it.
            static::failOnInvalidParent($content, $blueprint);

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
                $lastSegment = app(PathNormalizer::class)->lastSegment($content->path);

                if (filled($lastSegment)) {
                    $content->slug = $lastSegment;
                }
            }
        });

        static::deleted(function (): void {
            app(PathConflicts::class)->forget();
        });

        // A deleted page takes its address with it. `to_content_id` is nullOnDelete, so
        // inbound redirects would otherwise survive as active rows pointing nowhere —
        // shown as "Ziel fehlt" forever and pruned by nothing. Deactivated while the
        // target is still readable, so an admin can repoint them instead of guessing.
        static::deleting(function (Model $content): void {
            app(ContentRenameRedirects::class)->deactivateInbound($content);
        });

        // Renaming/moving a page moves its subtree: children re-save, which re-runs
        // the same parent-driven path composition per child (recursively down the
        // tree).
        static::saved(function (Model $content): void {
            // Any write makes the request's cached collision answers claims about a table
            // that no longer exists in that shape.
            app(PathConflicts::class)->forget();

            if (! $content->wasChanged('path')) {
                return;
            }

            // Every row that moves keeps its old address, and it does so HERE rather than
            // in the panel: a path moves from far more places than a form. A revision
            // restore, the Duplizieren action, a table reorder, an import or a console
            // command all run this hook, and none of them has anyone to ask. The cascade
            // below re-saves each descendant, so every one of them writes its own.
            app(ContentRenameRedirects::class)->followRename($content);

            // All or nothing: this runs inside save()'s transaction, so a descendant that
            // fails to save rolls back the whole move — this record's own UPDATE and the
            // redirects above included — instead of leaving half a subtree at the new
            // prefix. The saving guard rejects a move whose subtree does not fit; what it
            // cannot see is a row written between that check and this cascade.
            // childrenOf() is tenant-scoped, so a row of another tenant that names this one
            // as its parent is never saved by THIS tenant's rename.
            app(ContentTree::class)
                ->childrenOf($content)
                ->each(function (Model $child) use ($content): void {
                    // Composed against this record as it now stands, without reading it back.
                    $child->setRelation('parent', $content);
                    $child->save();
                });
        });
    }

    /**
     * Save the record — and whatever its new path drags along — as one write.
     *
     * A path change re-saves the whole subtree onto the new prefix and leaves forwarding
     * redirects behind (the saved hook above), so a save that fails halfway must not keep
     * its first half. This is Laravel's saveOrFail() made the default: every writer — the
     * panel, a console command, an import, a queued job — gets the transaction, not only
     * the callers that remember to open one. Inside a caller's transaction it nests as a
     * savepoint.
     */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    /**
     * Refuse a `parent_id` that breaks the tree ({@see ValidParent}) — checked whenever the
     * parent, the tenant or the type changes, so a create, a duplicate, a move and a
     * restored revision all meet it, while the cascade's re-saves (none of which changes a
     * parent) do not pay for it.
     *
     * @throws ValidationException
     */
    protected static function failOnInvalidParent(Model $content, ?ContentBlueprint $blueprint): void
    {
        if (blank($content->getAttribute('parent_id'))
            || ! $content->isDirty(['parent_id', 'tenant_id', 'content_type'])) {
            return;
        }

        Validator::make(
            ['parent_id' => $content->getAttribute('parent_id')],
            ['parent_id' => [new ValidParent($content, $blueprint)]],
        )->validate();
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
     * The content's URL path: the stored `path`, normalized — or null when its blueprint
     * is non-routable, so a leftover path on such a record is never handed out.
     *
     * Reading the column rather than composing the path again is what keeps routing,
     * links, the sitemap and the breadcrumbs on one answer, and every call free of a walk
     * up the tree. The column is current by construction (see the class doc); a row that
     * drifted anyway — written past the hooks, or a blueprint changed under it — stays at
     * its stored address until `cms:paths:check --fix` moves it, redirect included.
     *
     * What a save WOULD store is {@see generatedPath()}.
     */
    public function resolvedPath(): ?string
    {
        $content = clone $this;

        static::ensureTenantLoaded($content);

        $blueprint = app(ContentBlueprintRegistry::class)->find(
            $content->getAttribute('content_type'),
            $content->tenant?->site_key,
        );

        if ($blueprint !== null && $blueprint->isRoutable() === false) {
            return null;
        }

        return app(PathNormalizer::class)->normalizeOrNull($content->getAttribute('path'));
    }

    /**
     * The path a save would store for the record as it stands — the {@see PathGenerator}'s
     * answer, asked on a clone because the generator writes the slug back onto the model
     * it is handed. For everything that previews a save: the form's path preview, the
     * collision guard and the redirect-shadowing check walking a pending rename.
     */
    public function generatedPath(): ?string
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
        return $this->resolvedPath() ?? app(ContentTree::class)->parentOf($this)?->resolvedPath();
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
