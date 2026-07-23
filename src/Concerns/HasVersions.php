<?php

namespace Mmoollllee\Cms\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Overtrue\LaravelVersionable\Versionable;
use Overtrue\LaravelVersionable\VersionStrategy;

/**
 * Content versioning for Content and Fragment models — every APPLIED change
 * (create, "Änderungen anwenden", restore) records a snapshot version that can
 * be inspected and restored via the revisions pages.
 *
 * Deliberately versionless:
 * - the `draft` stash — drafts are unapplied work; they persist via
 *   {@see HasDraft}'s column-targeted query update, which fires no model
 *   events (so no version is created), AND `draft` is excluded from the
 *   attribute whitelist here (so a snapshot never contains stash data),
 * - `sort` — table reordering would otherwise spam the history,
 * - tenancy/authorship bookkeeping (`tenant_id`, `created_by`, `updated_by`).
 *
 * The whitelist derives from the model's $fillable, so app-added columns are
 * versioned automatically; override {@see nonVersionableAttributes()} to
 * exclude more. The same list doubles as the vendor's blacklist
 * ({@see getDontVersionable()}) — belt and braces for models using
 * `$guarded = []` instead of $fillable, where the whitelist would be empty
 * and the vendor would otherwise version EVERYTHING (including the stash).
 *
 * Retention: `versionable.keep_versions` is bridged from cms.versions.keep
 * (CmsServiceProvider), and pruned versions are force-deleted
 * ({@see initializeHasVersions()}) — the vendor default would soft-delete
 * them, capping the visible history but never freeing rows.
 */
trait HasVersions
{
    use Versionable;

    public static function bootHasVersions(): void
    {
        // The CMS models delete HARD (no SoftDeletes) — the vendor's own
        // deleted-hook only cleans up for soft-deleting models
        // (isForceDeleting guard), which would leave orphaned version rows
        // ("Gelöschter Inhalt" forever in the dashboard widget).
        static::deleted(function (Model $model): void {
            $model->forceRemoveAllVersions();
        });
    }

    public function initializeHasVersions(): void
    {
        // Property is declared by the vendor trait (redeclaring it here would
        // collide); makes removeVersions()/removeAllVersions() delete hard.
        $this->forceDeleteVersion = true;
    }

    /**
     * Retention pruning (runs after every recorded version). The vendor
     * implementation soft-deletes regardless of $forceDeleteVersion — the
     * keep-cap must actually free rows, or the versions table only ever grows.
     */
    public function removeOldVersions(int $keep = 1): void
    {
        if ($keep <= 0) {
            return;
        }

        $this->latestVersions()->skip($keep)->take(PHP_INT_MAX)->get()->each->forceDelete();
    }

    /**
     * SNAPSHOT: every version carries the full whitelisted attribute set —
     * restoring a version never has to replay a diff chain (the upstream DIFF
     * strategy has known reconstruction bugs).
     */
    public function getVersionStrategy(): VersionStrategy
    {
        return VersionStrategy::SNAPSHOT;
    }

    /**
     * Vendor hook consulted by shouldBeVersioning(): decides WITHOUT computing
     * (and discarding) a full snapshot — the vendor default re-selects the
     * entire row per save just to make this boolean.
     */
    public function shouldVersioning(): bool
    {
        return ! $this->versions()->exists()
            || Arr::hasAny($this->getDirty(), $this->getVersionable());
    }

    /**
     * @return array<int, string>
     */
    public function getVersionable(): array
    {
        return array_values(array_diff($this->getFillable(), $this->nonVersionableAttributes()));
    }

    /**
     * @return array<int, string>
     */
    public function getDontVersionable(): array
    {
        return $this->nonVersionableAttributes();
    }

    /**
     * @return array<int, string>
     */
    protected function nonVersionableAttributes(): array
    {
        return ['draft', 'sort', 'tenant_id', 'created_by', 'updated_by'];
    }
}
