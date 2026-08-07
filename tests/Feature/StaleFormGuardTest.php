<?php

/*
 * The stale-form guard (GuardsRecordWrites) — the case editorial locking cannot
 * cover, because a lock is held per USER: the same person with the record open
 * in two tabs (or a write from outside the panel) would otherwise submit a full
 * form built from an outdated state and silently win.
 *
 * Every out-of-band write here goes through the query builder, i.e. exactly
 * what "the other tab already saved" leaves behind in the database.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Redirects\Pages\EditRedirect;
use Mmoollllee\Cms\Models\Redirect;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

function staleFixture(Tenant $tenant): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ausgangstitel',
        'path' => '/stale-fixture',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subWeek(),
    ]);
}

/** What the OTHER tab leaves behind once it has written. */
function writeOutOfBand(Content $record, array $attributes): void
{
    $record->newQueryWithoutScopes()->whereKey($record->getKey())->update($attributes);
}

it('refuses to apply over a record another tab has changed', function () {
    $record = staleFixture($this->tenant);

    $tab = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Aus Tab B']);

    writeOutOfBand($record, ['title' => 'Aus Tab A']);

    $tab->call('save')->assertNotified('Nicht gespeichert');

    expect($record->fresh()->title)->toBe('Aus Tab A');
});

it('refuses to stash a draft over a record another tab has changed', function () {
    $record = staleFixture($this->tenant);

    $tab = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Aus Tab B']);

    writeOutOfBand($record, ['title' => 'Aus Tab A']);

    $tab->call('saveDraft')
        ->assertReturned(false)
        ->assertNotified('Nicht gespeichert');

    expect($record->fresh()->hasDraft())->toBeFalse();
});

it('counts a draft stashed elsewhere as a change — it is one shared slot', function () {
    $record = staleFixture($this->tenant);

    $tab = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Aus Tab B']);

    $record->stashDraft(['title' => 'Entwurf aus Tab A']);

    $tab->call('save')->assertNotified('Nicht gespeichert');

    expect($record->fresh()->draftData()['title'])->toBe('Entwurf aus Tab A');
});

it('ignores reordering — a moved tree row must not block an open form', function () {
    $record = staleFixture($this->tenant);

    $tab = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Aus Tab B']);

    // Somebody drags the content tree around while this form is open.
    writeOutOfBand($record, ['sort' => 42, 'updated_at' => now()->addMinute()]);

    $tab->call('save')->assertHasNoFormErrors();

    expect($record->fresh()->title)->toBe('Aus Tab B');
});

it('keeps saving in the same tab after its own write', function () {
    $record = staleFixture($this->tenant);

    $tab = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Erster Speicherlauf'])
        ->call('save')
        ->assertHasNoFormErrors();

    $tab->fillForm(['title' => 'Zweiter Speicherlauf'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->title)->toBe('Zweiter Speicherlauf');
});

it('re-stamps after a draft stash so the same tab can keep going', function () {
    $record = staleFixture($this->tenant);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Entwurf eins'])
        ->call('saveDraft')
        ->assertReturned(true)
        ->fillForm(['title' => 'Entwurf zwei'])
        ->call('saveDraft')
        ->assertReturned(true);

    expect($record->fresh()->draftData()['title'])->toBe('Entwurf zwei');
});

it('ignores redirect hit counters — frontend traffic must not lock an editor out', function () {
    $redirect = Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/alt',
        'to_url' => 'https://example.test/neu',
        'status' => 301,
        'origin' => RedirectOrigin::Manual,
    ]);

    $tab = Livewire::test(EditRedirect::class, ['record' => $redirect->getKey()])
        ->fillForm(['to_url' => 'https://example.test/noch-neuer']);

    // HitRecorder counts every served redirect straight from the frontend.
    $redirect->newQueryWithoutScopes()->whereKey($redirect->getKey())
        ->update(['hits' => 41, 'last_hit_at' => now()]);

    $tab->call('save')->assertHasNoFormErrors();

    expect($redirect->fresh()->to_url)->toBe('https://example.test/noch-neuer');
});

it('never reaches the guard when the record itself is gone', function () {
    $record = staleFixture($this->tenant);

    $tab = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->fillForm(['title' => 'Aus Tab B']);

    $record->newQueryWithoutScopes()->whereKey($record->getKey())->delete();

    // Livewire re-resolves the record property on every hydration, so a form
    // whose record was deleted elsewhere dies before any of our write guards
    // run. Pinned so the guard is never credited with a case it cannot see.
    expect(fn () => $tab->call('save'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
