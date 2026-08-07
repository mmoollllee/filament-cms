<?php

namespace Mmoollllee\Cms\Filament\Concerns;

use Blendbyte\FilamentResourceLock\Models\Concerns\HasLocks;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;
use Mmoollllee\Cms\Support\Locking\Locks;

/**
 * Editorial locking for the engine's edit pages: opening a record claims it for
 * the current user, and everyone else gets the blocking "wird gerade
 * bearbeitet" modal until the holder leaves or the lock times out.
 *
 * The lock lifecycle, the modal and the presence heartbeat come from
 * blendbyte/filament-resource-lock. The engine deliberately does NOT use that
 * package's own page traits — and neither of them can simply be inherited:
 * - `UsesResourceLock::getFormActions()` collides fatally with
 *   {@see ManagesDrafts}, which owns the footer here ("Änderungen anwenden" /
 *   "Entwurf speichern"),
 * - its base `UsesLocks` carries its own `#[On('resourceLockObserver::renewLock')]`
 *   handler, and a trait cannot drop a method: mixing it in would register a
 *   SECOND heartbeat listener next to {@see renewResourceLock()}, running the
 *   vendor's `isLockedByCurrentUser()` — the call that fatals on a deleted
 *   lock holder and that this trait routes around,
 * - both assume every record carries {@see HasLocks}, while content models are
 *   app-owned and may not have adopted it yet.
 * This trait therefore drives the vendor MODEL api directly and gates every
 * entry point on {@see Locks::active()}, so a panel without the plugin or a
 * model without the trait silently keeps the unlocked flow.
 *
 * Wire protocol (dispatched by the vendor's `filament-resource-lock-observer`
 * Livewire component, which the plugin renders on every panel page):
 * - `resourceLockObserver::init` — page opened,
 * - `resourceLockObserver::renewLock` — presence heartbeat (needs polling
 *   enabled on the plugin, otherwise the lock is never refreshed and a long
 *   edit loses it mid-session),
 * - `resourceLockObserver::unload` — tab closing (best effort: the dispatch
 *   often dies with the page, so the timeout is the real safety net),
 * - `resourceLockObserver::unlock` — "übernehmen" pressed in the modal.
 *
 * The write paths are guarded independently of the modal ({@see save()} and
 * {@see GuardsRecordWrites}) — a lock the client can dismiss is not a lock.
 */
trait LocksRecords
{
    use GuardsRecordWrites;

    /** Page opened: claim the record, or present the blocking modal. */
    #[On('resourceLockObserver::init')]
    public function initResourceLock(): void
    {
        if ($this->lockableRecord() === null) {
            return;
        }

        $this->dispatch('enablePollingInResourceLockObserver');

        $this->claimResourceLockOrBlock();
    }

    /**
     * Presence heartbeat: refresh our own lock, or — when the holder has left
     * in the meantime — claim the record and dismiss the modal.
     *
     * Nothing in the DOM changes on the happy path, but this listener rides the
     * EDIT PAGE component, so a re-render would rebuild the entire form (every
     * builder block, every rich editor) and ship the whole snapshot back — four
     * times a minute, per open tab. Rendering is therefore skipped unless the
     * blocked state actually flipped.
     */
    #[On('resourceLockObserver::renewLock')]
    public function renewResourceLock(): void
    {
        if ($this->lockableRecord() === null) {
            return;
        }

        $wasBlocked = $this->blockedByResourceLock;

        $claimed = $this->claimResourceLockOrBlock();

        if ($wasBlocked === ! $claimed) {
            $this->skipRender();

            return;
        }

        if ($wasBlocked && $claimed) {
            $this->dispatch('close-modal', id: self::RESOURCE_LOCK_MODAL);

            Notification::make()
                ->success()
                ->title('Bearbeitung freigegeben')
                ->body('Die Seite ist jetzt für Sie freigegeben.')
                ->send();
        }
    }

    /** Tab closing: release our own lock so the next editor is not made to wait. */
    #[On('resourceLockObserver::unload')]
    public function releaseResourceLock(): void
    {
        $this->lockableRecord()?->unlock();
    }

    /**
     * "Übernehmen" from the modal — force-drops a foreign lock. The button is
     * already gated client-side by the observer component; re-checking here is
     * what actually enforces it, since the event can be dispatched by hand.
     */
    #[On('resourceLockObserver::unlock')]
    public function takeOverResourceLock(): void
    {
        $record = $this->lockableRecord();

        if ($record === null || ! Locks::canTakeOver()) {
            return;
        }

        // Not gated on the return value: unlock() reports false for a lock that
        // has ALREADY expired (it only drops live ones), and bailing there
        // would leave the user with the modal closed client-side and no lock at
        // all. claimResourceLockOrBlock() cleans an expired row up anyway.
        $record->unlock(force: true);
        $record->unsetRelation('resourceLock');

        if ($this->claimResourceLockOrBlock()) {
            $this->dispatch('close-modal', id: self::RESOURCE_LOCK_MODAL);
        }
    }

    /**
     * Last line of defence for the applied save: a user who dismissed the
     * modal client-side must still not write over the holder's work — and
     * neither must a form whose record moved on since it loaded, which the
     * lock cannot catch when both tabs belong to the SAME user
     * ({@see GuardsRecordWrites}).
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if (! $this->assertSafeToWrite()) {
            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);

        // Pages without ManagesDrafts (redirects, layout presets, menus) have
        // no rememberData() hook to re-stamp through.
        $this->stampRecordFingerprint();
    }

    /**
     * Claim the record for the current user, or raise the blocking modal.
     * Returns whether the lock is now ours.
     *
     * The foreign-lock case is answered BEFORE handing over to the vendor's
     * lock(): that one routes through `isLockedByCurrentUser()`, which reads
     * `$resourceLock->user->id` and fatals on a lock whose user has since been
     * deleted — reachable because the engine's migration drops the vendor's
     * foreign key (the user model is app-configurable). Everything that
     * survives the check is free, expired or already ours, none of which walk
     * the relation.
     */
    protected function claimResourceLockOrBlock(): bool
    {
        $record = $this->lockableRecord();

        if ($record === null) {
            return false;
        }

        $record->unsetRelation('resourceLock');

        if (Locks::heldByOther($record)) {
            $this->blockOnResourceLock($record);

            return false;
        }

        if (Locks::heldByCurrentUser($record)) {
            // The refresh, straight on the relation: the vendor's lock() would
            // reach the same touch() through isLockedByCurrentUser(), which
            // re-reads the whole users row on every beat just to compare an id
            // we already compared.
            $record->resourceLock()->touch();
        } else {
            // Free or expired — lock() creates, cleaning up an expired row on
            // the way. No live foreign lock is left for it to walk.
            $record->lock();
        }

        $this->blockedByResourceLock = false;
        $this->resourceLockOwner = null;

        return true;
    }
}
