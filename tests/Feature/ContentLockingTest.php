<?php

/*
 * Editorial locking (LocksRecords + GuardsRecordWrites, on top of
 * blendbyte/filament-resource-lock):
 *
 * - opening an edit page claims the record for the current user,
 * - a second editor is blocked by the modal and cannot write — neither the
 *   applied save nor the draft stash, which writes outside the save pipeline,
 * - an expired lock is claimed silently; leaving the page releases it,
 * - taking a record over is a tenant-admin privilege,
 * - models that have not adopted HasLocks keep the unlocked flow.
 */

use Blendbyte\FilamentResourceLock\Models\ResourceLock;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Models\NotFoundLog;
use Mmoollllee\Cms\Support\Locking\Locks;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

function lockFixture(Tenant $tenant): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Gesperrter Titel',
        'path' => '/lock-fixture',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subWeek(),
    ]);
}

/** Hand the record to somebody else, as if they had opened it first. */
function lockedByOther(Content $record, string $email = 'editor-a@example.test'): User
{
    $holder = User::where('email', $email)->firstOrFail();

    $lock = new ResourceLock;
    $lock->user_id = $holder->getKey();

    $record->resourceLock()->save($lock);
    $record->unsetRelation('resourceLock');

    return $holder;
}

it('claims the record when the edit page reports itself open', function () {
    $record = lockFixture($this->tenant);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->assertDispatched('enablePollingInResourceLockObserver')
        ->assertNotDispatched('open-modal');

    expect($record->fresh()->isLockedByCurrentUser())->toBeTrue();
});

it('blocks a second editor with the modal instead of a lock of their own', function () {
    $record = lockFixture($this->tenant);
    $holder = lockedByOther($record);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->assertDispatched('open-modal', id: 'resourceIsLockedNotice', resourceLockOwner: $holder->name)
        ->assertSet('resourceLockOwner', $holder->name);

    expect(ResourceLock::query()->count())->toBe(1);
});

it('refuses the applied save while somebody else holds the record', function () {
    $record = lockFixture($this->tenant);
    lockedByOther($record);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Überschrieben'])
        ->call('save')
        ->assertNotified('Nicht gespeichert')
        ->assertDispatched('open-modal', id: 'resourceIsLockedNotice');

    expect($record->fresh()->title)->toBe('Gesperrter Titel');
});

it('refuses the draft stash too — the draft column is a single shared slot', function () {
    $record = lockFixture($this->tenant);
    lockedByOther($record);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Fremder Entwurf'])
        ->call('saveDraft')
        ->assertReturned(false)
        ->assertNotified('Nicht gespeichert');

    expect($record->fresh()->hasDraft())->toBeFalse();
});

it('claims a record whose lock has aged out', function () {
    $record = lockFixture($this->tenant);
    lockedByOther($record);

    // The plugin's timeout is configured in seconds on the panel; age the lock
    // past it rather than sleeping.
    $record->resourceLock->forceFill(['updated_at' => now()->subDay()])->save();

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->assertNotDispatched('open-modal');

    expect($record->fresh()->isLockedByCurrentUser())->toBeTrue()
        ->and(ResourceLock::query()->count())->toBe(1);
});

it('releases the lock when the page unloads', function () {
    $record = lockFixture($this->tenant);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->dispatch('resourceLockObserver::unload');

    expect(ResourceLock::query()->count())->toBe(0);
});

it('picks the record up on the next heartbeat once the holder has left', function () {
    $record = lockFixture($this->tenant);
    lockedByOther($record);

    $page = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->assertSet('resourceLockOwner', 'Erik Editor');

    ResourceLock::query()->delete();

    $page->dispatch('resourceLockObserver::renewLock')
        ->assertSet('resourceLockOwner', null)
        ->assertDispatched('close-modal', id: 'resourceIsLockedNotice')
        ->assertNotified('Bearbeitung freigegeben');

    expect($record->fresh()->isLockedByCurrentUser())->toBeTrue();
});

it('lets a tenant admin take a held record over', function () {
    $this->tenant = switchToMarketingPanelUser('admin-a@example.test');
    $record = lockFixture($this->tenant);
    lockedByOther($record);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->dispatch('resourceLockObserver::unlock')
        ->assertSet('resourceLockOwner', null)
        ->assertDispatched('close-modal', id: 'resourceIsLockedNotice');

    expect($record->fresh()->isLockedByCurrentUser())->toBeTrue();
});

it('keeps take-over away from editors', function () {
    $this->tenant = switchToMarketingPanelUser('editor-a@example.test');
    $record = lockFixture($this->tenant);
    $holder = lockedByOther($record, 'admin-a@example.test');

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->dispatch('resourceLockObserver::unlock')
        ->assertSet('resourceLockOwner', $holder->name);

    expect($record->fresh()->resourceLock->user_id)->toBe($holder->getKey());
});

it('survives a lock whose holder has since been deleted', function () {
    $record = lockFixture($this->tenant);
    $holder = lockedByOther($record);

    // The engine's migration drops the vendor foreign key (the user model is
    // app-configurable), so orphan lock rows are reachable — and the vendor's
    // lock() walks $resourceLock->user->id without a null check.
    $holder->delete();

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->assertOk()
        ->assertDispatched('open-modal', id: 'resourceIsLockedNotice')
        ->assertSet('resourceLockOwner', null)
        ->assertSet('blockedByResourceLock', true);
});

it('claims the record on take-over even when the lock expired in the meantime', function () {
    $this->tenant = switchToMarketingPanelUser('admin-a@example.test');
    $record = lockFixture($this->tenant);
    lockedByOther($record);

    $page = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->dispatch('resourceLockObserver::init')
        ->assertSet('blockedByResourceLock', true);

    // unlock(force: true) reports false for an already-expired lock, which must
    // not stop the take-over — the modal is closed client-side regardless.
    $record->resourceLock->forceFill(['updated_at' => now()->subDay()])->save();

    $page->dispatch('resourceLockObserver::unlock')
        ->assertDispatched('close-modal', id: 'resourceIsLockedNotice')
        ->assertSet('blockedByResourceLock', false);

    expect($record->fresh()->isLockedByCurrentUser())->toBeTrue();
});

it('refuses to discard a draft while somebody else holds the record', function () {
    $record = lockFixture($this->tenant);
    $record->stashDraft(['title' => 'Fremder Entwurf']);
    lockedByOther($record);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->callAction('discardDraft')
        ->assertNotified('Nicht gespeichert');

    expect($record->fresh()->hasDraft())->toBeTrue();
});

it('stays out of the way for models that have not adopted the trait', function () {
    expect(Locks::supported(NotFoundLog::class))->toBeFalse()
        ->and(Locks::supported(Content::class))->toBeTrue()
        ->and(Locks::heldByOther(new NotFoundLog))->toBeFalse();
});
