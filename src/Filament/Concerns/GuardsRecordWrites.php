<?php

namespace Mmoollllee\Cms\Filament\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Mmoollllee\Cms\Support\Locking\Locks;

/**
 * "May this form write its record?" — the two ways the answer is no, behind one
 * call ({@see assertSafeToWrite()}):
 *
 * - SOMEBODY ELSE holds the record. Editorial locking ({@see LocksRecords})
 *   normally keeps them out with the blocking modal, but a lock a client can
 *   dismiss is not a lock, so the write path re-checks and re-raises the modal.
 * - The RECORD MOVED since this form loaded. A lock is held per USER, so a
 *   second tab of the same person is never blocked by it; neither are writes
 *   from outside the panel (imports, console commands, a shared account
 *   elsewhere). Both tabs would otherwise submit a full form built from
 *   different states and the last one would win silently.
 *
 * Both checks are needed by every write path, including the ones OUTSIDE the
 * save pipeline: {@see ManagesDrafts} stashes and discards straight into the
 * `draft` column, which is one shared slot per record and leaves no version
 * behind. Splitting them cost four copy-pasted call sites that each had to
 * remember both halves, so they live together — call assertSafeToWrite() and
 * abort on false.
 *
 * The lock half turns itself off unless the plugin is on the panel AND the
 * model adopted the trait ({@see Locks}); the stale half needs neither.
 */
trait GuardsRecordWrites
{
    /** Vendor modal raised for a record somebody else holds. */
    protected const RESOURCE_LOCK_MODAL = 'resourceIsLockedNotice';

    /**
     * Whether this page is currently showing the blocking modal. Tracked
     * separately from {@see $resourceLockOwner} because the owner NAME is
     * legitimately null while blocked (privacy flag off, deleted user), and it
     * cannot be re-derived from the database either: by the time the heartbeat
     * asks, the holder may have left — which is precisely when the modal needs
     * closing.
     */
    #[Locked]
    public bool $blockedByResourceLock = false;

    /** Display name of the current holder — only set while WE are blocked. */
    #[Locked]
    public ?string $resourceLockOwner = null;

    /**
     * Fingerprint of the record as THIS form loaded it. `#[Locked]`: the whole
     * guard would be pointless if the client could hand back a fresh value.
     */
    #[Locked]
    public ?string $loadedRecordFingerprint = null;

    /**
     * Per-request memo, deliberately NOT a Livewire property (protected state
     * is not serialized, so it resets each request): a save funnels through
     * both `rememberData()` and the page's own re-stamp, and re-reading the
     * whole row twice for the same post-write state is pure waste.
     */
    protected bool $recordFingerprintStamped = false;

    /**
     * The single write guard. False (plus notification, plus the modal when a
     * foreign lock is the reason) means: do not write.
     */
    public function assertSafeToWrite(): bool
    {
        return $this->assertResourceUnlocked() && $this->assertRecordNotStale();
    }

    /**
     * Livewire trait hook — runs after mount() on the first request (record
     * resolved, form filled) and after hydrate() on every later one, where the
     * property is already restored and `??=` keeps the ORIGINAL stamp. That is
     * the point: a stamp refreshed per request would never detect drift.
     */
    public function bootedGuardsRecordWrites(): void
    {
        $this->loadedRecordFingerprint ??= $this->currentRecordFingerprint();
    }

    /**
     * Re-stamp after our OWN write — the form is pristine against the new row.
     *
     * PROTECTED on purpose: Livewire exposes every public non-static component
     * method as a client-callable action, so a public one here would let the
     * browser re-stamp the #[Locked] fingerprint and save straight over another
     * editor's write. Both call sites are internal.
     */
    protected function stampRecordFingerprint(): void
    {
        if ($this->recordFingerprintStamped) {
            return;
        }

        $this->loadedRecordFingerprint = $this->currentRecordFingerprint();
        $this->recordFingerprintStamped = true;
    }

    protected function assertResourceUnlocked(): bool
    {
        $record = $this->lockableRecord();

        if ($record === null) {
            return true;
        }

        // The relation is cached from the request that rendered the form; the
        // record may have been taken over since. Re-read before refusing.
        $record->unsetRelation('resourceLock');

        if (! Locks::heldByOther($record)) {
            return true;
        }

        $this->blockOnResourceLock($record);

        Notification::make()
            ->danger()
            ->title('Nicht gespeichert')
            ->body($this->resourceLockOwner === null
                ? 'Diese Seite wird gerade von jemand anderem bearbeitet.'
                : "Diese Seite wird gerade von {$this->resourceLockOwner} bearbeitet.")
            ->send();

        return false;
    }

    protected function assertRecordNotStale(): bool
    {
        if ($this->loadedRecordFingerprint === null) {
            return true;
        }

        if ($this->currentRecordFingerprint() === $this->loadedRecordFingerprint) {
            return true;
        }

        Notification::make()
            ->warning()
            ->persistent()
            ->title('Nicht gespeichert')
            ->body('Der Inhalt wurde zwischenzeitlich anderswo bearbeitet. Bitte die Seite neu laden, um keine Änderungen zu überschreiben.')
            ->send();

        return false;
    }

    /**
     * Present the vendor's non-dismissable "locked" modal. Re-dispatching is
     * skipped while it is already up for the same holder — a blocked tab keeps
     * heartbeating, and that is exactly the tab users leave sitting.
     */
    protected function blockOnResourceLock(?Model $record): void
    {
        $owner = Locks::ownerName($record);

        if ($this->blockedByResourceLock && $owner === $this->resourceLockOwner) {
            return;
        }

        $this->blockedByResourceLock = true;
        $this->resourceLockOwner = $owner;

        $this->dispatch(
            'open-modal',
            id: self::RESOURCE_LOCK_MODAL,
            returnUrl: $this->resourceLockReturnUrl(),
            resourceLockOwner: $owner,
        );
    }

    /** Where the modal's "zurück" button sends a blocked user. */
    protected function resourceLockReturnUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * The record to lock — null whenever locking cannot apply here: no record
     * yet (create pages), no plugin on this panel, or a model that has not
     * adopted the trait.
     */
    protected function lockableRecord(): ?Model
    {
        if (! isset($this->record) || ! $this->record instanceof Model) {
            return null;
        }

        return Locks::active($this->record) ? $this->record : null;
    }

    /**
     * Hash of the stored row, or null when there is nothing to compare against
     * (create pages, or a record deleted underneath us — Livewire re-resolves
     * the record property on every hydration, so that one dies before any
     * guard runs).
     */
    protected function currentRecordFingerprint(): ?string
    {
        if (! isset($this->record) || ! $this->record instanceof Model) {
            return null;
        }

        // fresh() reads through the WRITE connection: on an install with a read
        // replica, comparing against a lagging replica could clear a write that
        // is only stale on paper — the one outcome this guard exists to stop.
        $stored = $this->record->fresh();

        if ($stored === null) {
            return null;
        }

        // getAttributes() on a freshly queried model returns the RAW driver
        // values (no casts, no accessors), so the hash is stable across
        // requests and drivers. serialize(), not json_encode(): the latter
        // returns false on binary or invalid-UTF-8 column data, which cast to
        // string would hash every affected row to the SAME constant and turn
        // the guard off silently. Columns the FORM carries cannot reach that
        // state (Livewire's own snapshot encoder throws on them first), but the
        // fingerprint covers the whole row, form-bound or not.
        $attributes = Arr::except($stored->getAttributes(), $this->fingerprintIgnoredAttributes());

        ksort($attributes);

        return md5(serialize($attributes));
    }

    /**
     * Columns whose change must NOT invalidate an open form — everything a
     * MACHINE writes behind the editor's back:
     * - `sort`: dragging the content tree touches rows others have open,
     * - `path`/`slug`: renaming a page re-saves its whole subtree
     *   ({@see \Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug}), so without
     *   this an editor holding a child page is refused forever — and refused for
     *   nothing, since their own save recomputes the identical path,
     * - the timestamps, which move along with every one of these,
     * - `deleted_at`: an in-place RestoreAction on the very same page would
     *   otherwise make the user's next save fail against their own click,
     * - `hits`/`last_hit_at`: {@see \Mmoollllee\Cms\Support\Routing\HitRecorder}
     *   counts redirect and 404 traffic from the FRONTEND, so without this a
     *   redirect with steady traffic could never be saved from the panel,
     * - the tenancy/authorship bookkeeping.
     *
     * Content columns stay in deliberately, even when a job rewrites them
     * ({@see \Mmoollllee\Cms\Jobs\ConvertVideoForWeb} rewrites `blocks` after a
     * re-encode): saving a form loaded before that would revert the conversion,
     * which is exactly the overwrite this guard exists to stop.
     *
     * Override to add app-specific machine-written columns.
     *
     * @return array<int, string>
     */
    protected function fingerprintIgnoredAttributes(): array
    {
        return [
            'sort',
            'path',
            'slug',
            'created_at',
            'updated_at',
            'deleted_at',
            'hits',
            'last_hit_at',
            'tenant_id',
            'created_by',
            'updated_by',
        ];
    }
}
