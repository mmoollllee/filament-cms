<?php

namespace Mmoollllee\Cms\Support\Routing;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\CmsServiceProvider;
use Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Http\Controllers\Frontend\ResolveNotFoundController;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Content\ContentTree;
use Mmoollllee\Cms\Support\Content\PathConflicts;

/**
 * Keeps a moved page reachable under the address it used to have.
 *
 * Nothing else in the CMS catches a rename: the 404 pipeline writes a redirect only once a
 * JS-capable visitor has hit the dead address often enough AND the fuzzy resolver is very
 * confident, and it only gets that confident when the last segment stayed the same — which
 * is a move, never a rename.
 *
 * Driven from the model's hooks ({@see GeneratesPathAndSlug}), not from a form, because a
 * path moves from far more places than the panel: a revision restore, the Duplizieren
 * action, a table reorder, an import, a console command. None of those has anyone to ask,
 * so the address is kept rather than offered. Each row keeps its OWN address as it is
 * written, which is why the subtree a rename cascades over needs no separate walk here.
 *
 * The target is always `to_content_id`, never a frozen `to_url`: the redirect then follows
 * the page through every later rename instead of forming a chain to maintain.
 */
class ContentRenameRedirects
{
    public function __construct(
        protected PathNormalizer $normalizer,
        protected ContentTree $tree,
    ) {}

    /**
     * Whether the redirect subsystem is switched on at all.
     *
     * Asked BEFORE any query. The redirect migrations are published, not loaded
     * ({@see CmsServiceProvider}), so a consumer that never published them
     * has no `redirects` table — and until this class existed, nothing on the content-save
     * path touched it. Reaching the table before consulting the switch would turn every
     * rename in such an app into a QueryException with no way to opt out.
     */
    public function available(): bool
    {
        return (bool) config('cms.redirects.enabled', true);
    }

    /** Whether a rename should leave a forwarding address behind. */
    public function enabled(): bool
    {
        return $this->available()
            && config('cms.redirects.on_rename', 'keep') === 'keep';
    }

    /**
     * Follow a record onto its new address: hand back a redirect it has just moved BACK
     * onto, and leave a forwarding one on the address it left.
     *
     * Called from the saved hook, so the old value is the record's own original — measured
     * rather than predicted, and as correct for a cascaded descendant as for the record
     * the editor actually touched.
     */
    public function followRename(Model $content): void
    {
        if (! $this->available()) {
            return;
        }

        $from = $this->normalizer->normalizeOrNull($content->getOriginal('path'));
        $to = $this->normalizer->normalizeOrNull($content->getAttribute('path'));

        // The record lost its URL entirely — a routable type switched to a non-routable
        // one. There is nowhere to forward to, and the redirects that pointed here would
        // otherwise stay active with a target that resolves to nothing.
        if ($to === null) {
            $this->deactivateInbound($content);

            return;
        }

        if ($from === null || $from === $to) {
            return;
        }

        // Moving back onto an address this page's own redirect stands on. That redirect now
        // points at the page living there, and a redirect answers BEFORE content does, so
        // leaving it would make the page unreachable at its own address by looping onto
        // itself. Nobody needs to be asked: the loop is never what anyone wanted.
        $loop = $this->standingOn($content->getAttribute('tenant_id'), $to);

        if ($loop !== null && $this->pointsAt($loop, $content)) {
            $this->release($loop);
        }

        if (! $this->enabled()) {
            return;
        }

        // Somebody else already lives there — a swap within one request. Sending the old
        // address here would take it away from its new owner.
        if ($this->tree->ownerOf($content->getAttribute('tenant_id'), $from, [$content->getKey()]) !== null) {
            return;
        }

        $this->write($content, $from);
    }

    /** Whether $redirect is $content's own forwarding address. */
    public function pointsAt(Redirect $redirect, Model $content): bool
    {
        return $redirect->to_content_id !== null
            && (int) $redirect->to_content_id === (int) $content->getKey();
    }

    /**
     * The redirect that would SHADOW $path for $content — the one reason a page put there
     * would be unreachable at its own address.
     *
     * Narrower than {@see standingOn()} on purpose, and the only question the panel should
     * ask. Two active rows are not shadows: the record's own forwarding address (it is on
     * the way home, and the saved hook clears it), and a row whose target resolves to
     * nothing, which {@see RedirectResolver} skips when it builds the map.
     */
    public function shadowing(int|string|null $tenantId, ?string $path, ?Model $content = null): ?Redirect
    {
        $standing = $this->standingOn($tenantId, $path);

        if ($standing === null) {
            return null;
        }

        if ($content !== null && $content->exists && $this->pointsAt($standing, $content)) {
            return null;
        }

        return $standing->resolvedTarget() === null ? null : $standing;
    }

    /**
     * The first row a save of $content would leave shadowed — the record itself, or a
     * descendant the rename cascades onto an address a redirect already answers.
     *
     * The cascade re-saves every child onto the new prefix ({@see GeneratesPathAndSlug}),
     * and those N writes are not the record a form validated. Without this, renaming a
     * parent to a prefix somebody has redirects under moves a child under one silently:
     * the redirect answers before content, so the child is unreachable at its own address
     * with nothing reported anywhere.
     *
     * Mirrors {@see PathConflicts::firstSubtreeConflict()},
     * which asks the same question about content.
     *
     * @param  array<int|string, true>  $seen  guards a malformed parent_id cycle
     * @return array{record: Model, redirect: Redirect}|null
     */
    public function firstShadowedInSubtree(Model $content, array &$seen = []): ?array
    {
        if (! $this->available() || ! $content->exists) {
            return null;
        }

        foreach ($this->tree->childrenOf($content) as $child) {
            if (isset($seen[$child->getKey()])) {
                continue;
            }

            $seen[$child->getKey()] = true;

            // Compose against the parent as it WILL be stored, not as the database still
            // has it — the same seeding PathConflicts uses to walk a pending rename.
            $probe = clone $child;
            $probe->setRelation('parent', $content);
            $probe->setAttribute('path', $probe->resolvedPath());

            $shadow = $this->shadowing($probe->getAttribute('tenant_id'), $probe->getAttribute('path'), $probe);

            if ($shadow !== null) {
                return ['record' => $child, 'redirect' => $shadow];
            }

            $deeper = $this->firstShadowedInSubtree($probe, $seen);

            if ($deeper !== null) {
                return $deeper;
            }
        }

        return null;
    }

    /**
     * The active redirect stored on $path — the row, whether or not it answers anything.
     */
    public function standingOn(int|string|null $tenantId, ?string $path): ?Redirect
    {
        $normalized = $this->normalizer->normalizeOrNull($path);

        if (! $this->available() || $normalized === null || $tenantId === null) {
            return null;
        }

        return Redirect::query()
            ->where('tenant_id', $tenantId)
            ->where('from_path', $normalized)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Hand the address back. Soft-deleted rather than erased: the unique index spans
     * trashed rows, so the row stays a tombstone and the 404 auto-resolver will not put a
     * redirect back on this path by itself ({@see ResolveNotFoundController}).
     *
     * Switched off in the same breath, quietly. A tombstone that keeps `is_active = true`
     * comes back live if an admin ever uses the table's "Wiederherstellen" action, and a
     * plain save() here would hit the auto-to-manual promotion hook on the way past.
     */
    public function release(Redirect $redirect): void
    {
        if ($redirect->is_active) {
            $redirect->forceFill(['is_active' => false])->saveQuietly();
        }

        $redirect->delete();
    }

    /**
     * Switch off the redirects pointing at a record that is losing its address.
     *
     * `to_content_id` is `nullOnDelete`, so a delete strips their destination without
     * touching anything else: left alone they would stay ACTIVE with no target at all —
     * shown in the list as live redirects, answering with nothing, pruned by nothing, and
     * holding their `from_path` against the next page that wants it.
     *
     * Curated rows are left alone. A Manual redirect is somebody's deliberate decision,
     * and an inactive one is easy to miss; left active with a missing target it shows up
     * in the list as exactly the thing it now is, something an admin has to repoint.
     */
    public function deactivateInbound(Model $content): int
    {
        if (! $this->available()) {
            return 0;
        }

        return Redirect::query()
            ->where('tenant_id', $content->getAttribute('tenant_id'))
            ->where('to_content_id', $content->getKey())
            ->where('is_active', true)
            ->whereNot('origin', RedirectOrigin::Manual)
            ->update(['is_active' => false]);
    }

    protected function write(Model $content, string $from): bool
    {
        $existing = Redirect::withTrashed()
            ->where('tenant_id', $content->getAttribute('tenant_id'))
            ->where('from_path', $from)
            ->first();

        if ($existing !== null && $this->isCurated($existing)) {
            return false;
        }

        $redirect = $existing ?? new Redirect;

        $redirect->forceFill([
            'tenant_id' => $content->getAttribute('tenant_id'),
            'from_path' => $from,
            'to_content_id' => $content->getKey(),
            'to_url' => null,
            // The page really moved, so the address is permanently elsewhere — the same
            // status the redirect form and the auto-to-manual promotion use.
            'status_code' => (int) config('cms.redirects.confirmed_status', 301),
            'is_active' => true,
            'origin' => RedirectOrigin::Rename,
            // Lifts a tombstone in the same write. `deleted_at` is not fillable, so this
            // has to be forced: a restore() afterwards would be a second write, and a
            // second full rebuild of the tenant's redirect map.
            'deleted_at' => null,
        ]);

        if (! $redirect->exists) {
            $redirect->created_by = $this->currentUserId();
        }

        // The model promotes an automatic row to Manual as soon as a human touches it —
        // and from its point of view this IS a human, so it would overwrite the Rename
        // origin set above. write() would then refuse the same address on the next move
        // (see isCurated()), leaving the page with no forwarding address at all.
        Redirect::$autoWriting = true;

        try {
            $redirect->save();
        } finally {
            Redirect::$autoWriting = false;
        }

        return true;
    }

    /**
     * Whether a person authored this row's destination, so a rename must leave it alone.
     *
     * Manual is the explicit case. The other two are a Rename row an admin has since
     * edited: a rename only ever writes `to_content_id`, so an external `to_url` or a note
     * can only have come from a person — and updateOrCreate-ing over it would wipe both
     * without a word.
     *
     * Deliberately NOT {@see RedirectOrigin::isHumanAuthored()}: an untouched Rename row
     * is ours to update, and has to be, or a page renamed twice onto the same old address
     * would stop being followed on the second move.
     */
    protected function isCurated(Redirect $redirect): bool
    {
        return $redirect->origin === RedirectOrigin::Manual
            || filled($redirect->to_url)
            || filled($redirect->notes);
    }

    /**
     * The acting user, or null outside a panel.
     *
     * Filament::auth() resolves the current-or-DEFAULT panel and throws when an app marks
     * none as default — and this class runs from a model hook, which the console, an
     * import and a queued job all reach with no panel at all.
     */
    protected function currentUserId(): int|string|null
    {
        return Filament::getCurrentPanel()?->auth()->id() ?? auth()->id();
    }
}
