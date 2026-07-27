<?php

/*
 * The "Veröffentlicht" toggle + publishing window (PublishingFields):
 *
 * - `is_published` is virtual (no DB column) and CANNOT self-derive (its
 *   Boolean state cast folds "unfilled" into false), so ContentEditPage seeds
 *   it from the — live or draft — publishing window on every fill.
 * - The window pickers only show while the toggle is on, but stay dehydrated
 *   (`dehydratedWhenHidden`): switching a live page off must actually persist
 *   the cleared window.
 * - The status badge is READ-ONLY, derived via ContentStatus::forWindow() —
 *   "Geplant"/"Abgelaufen" are outcomes of the entered window, never inputs.
 * - `visibility` persists as a Hidden field (the "Nur Eingeloggt" UI is gone);
 *   legacy values round-trip unchanged.
 */

use Carbon\CarbonInterface;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $this->tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setTenant($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
});

/**
 * Create a marketing `default.page` with the given publishing window.
 */
function makeContent(Tenant $tenant, string $path, ?CarbonInterface $from, ?CarbonInterface $until = null): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Fixture '.$path,
        'path' => $path,
        'visibility' => ContentVisibility::Public,
        'publish_from' => $from,
        'publish_until' => $until,
    ]);
}

it('hydrates the toggle ON for an already published record and shows the status badge', function () {
    // Seeded home page: publish_from a week ago, no publish_until → published.
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    expect($home->status()->value)->toBe('published');

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        ->assertFormSet(['is_published' => true])
        ->assertSee('Für Besucher sichtbar.');
});

it('hydrates the toggle OFF for an unpublished record', function () {
    $record = makeContent($this->tenant, '/fixture-unpublished', from: null);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['is_published' => false])
        ->assertSee('Für Besucher nicht sichtbar');
});

it('shows "Geplant" for a scheduled record — toggle ON, badge derived', function () {
    $record = makeContent($this->tenant, '/fixture-scheduled', from: now()->addWeek());

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['is_published' => true])
        ->assertSee('automatisch online');
});

it('shows "Abgelaufen" for an expired record — toggle ON, badge derived', function () {
    $record = makeContent($this->tenant, '/fixture-expired', from: now()->subWeek(), until: now()->subDay());

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['is_published' => true])
        ->assertSee('Veröffentlichung endete');
});

it('explains a bounded window on a published record: visible now, auto-hidden later', function () {
    $record = makeContent($this->tenant, '/fixture-bounded', from: now()->subWeek(), until: now()->addWeek());

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertSee('Für Besucher sichtbar')
        ->assertSee('automatisch ausgeblendet');
});

it('defaults the toggle to OFF on the create form', function () {
    Livewire::test(CreateContent::class)
        ->assertOk()
        ->assertFormSet(['is_published' => false]);
});

it('seeds publish_from with now when toggling ON, clears the window when toggling OFF', function () {
    $record = makeContent($this->tenant, '/fixture-toggle', from: null);

    $component = Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['is_published' => false, 'publish_from' => null])
        // ->set() fires afterStateUpdated (fillForm would not).
        ->set('data.is_published', true);

    expect($component->instance()->data['publish_from'])->not->toBeNull();

    $component
        ->set('data.is_published', false)
        ->assertFormSet(['publish_from' => null, 'publish_until' => null]);
});

it('flips the toggle OFF when publish_from is cleared', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        ->assertFormSet(['is_published' => true])
        ->set('data.publish_from', null)
        ->assertFormSet(['is_published' => false]);
});

it('actually persists the cleared window when unpublishing via the toggle (dehydratedWhenHidden)', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    expect($home->publish_from)->not->toBeNull();

    // Toggling off hides the pickers — their (cleared) state must still
    // dehydrate, or the "unpublish" would silently keep the page live.
    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        ->set('data.is_published', false)
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $home->fresh();

    expect($fresh->publish_from)->toBeNull()
        ->and($fresh->publish_until)->toBeNull()
        ->and($fresh->status()->value)->toBe('draft');
});

it('round-trips a legacy members visibility unchanged and explains its real effect', function () {
    $record = makeContent($this->tenant, '/fixture-members', from: now()->subWeek());
    $record->forceFill(['visibility' => ContentVisibility::Members])->save();

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        // No visibility select anymore ("Zugriff" was its label) — instead the
        // effect summary flags the legacy state honestly: hidden, not public.
        ->assertDontSee('Zugriff')
        ->assertSee('Altbestand')
        ->assertSee('Für Besucher nicht sichtbar')
        ->fillForm(['title' => 'Umbenannt'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The stored value is not silently flipped by an unrelated save.
    expect($record->fresh()->visibility)->toBe(ContentVisibility::Members);
});

it('shows the reset hint action only when publish_from is dirty and reverts it (re-syncing the toggle)', function () {
    // Home page: publish_from a week ago, no publish_until → published.
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    $resetFrom = TestAction::make('reset_publish_from')->schemaComponent('publish_from');

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        // Pristine load: value matches the saved record → the reset action is hidden.
        // A hidden hint action is not resolvable, so "does not exist" is the assertion.
        ->assertActionDoesNotExist($resetFrom)
        // Change the field → the reset action appears.
        ->set('data.publish_from', now()->addWeek()->format('Y-m-d H:i:s'))
        ->assertSee('automatisch online')
        ->assertActionVisible($resetFrom)
        // Click the reset button (the raw mountAction the UI dispatches while it is
        // visible): the value reverts to the saved state and the toggle re-syncs.
        ->call('mountAction', 'reset_publish_from', [], ['schemaComponent' => 'form.publish_from'])
        ->assertFormSet(['is_published' => true])
        ->assertActionDoesNotExist($resetFrom);
});

it('reverts publish_until to its saved (empty) value via the reset hint action', function () {
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    $resetUntil = TestAction::make('reset_publish_until')->schemaComponent('publish_until');

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        ->assertActionDoesNotExist($resetUntil)
        // A past "bis" expires an otherwise-published page.
        ->set('data.publish_until', now()->subDay()->format('Y-m-d H:i:s'))
        ->assertSee('Veröffentlichung endete')
        ->assertActionVisible($resetUntil)
        ->call('mountAction', 'reset_publish_until', [], ['schemaComponent' => 'form.publish_until'])
        // Saved value was empty → reverts to null, page is published again.
        ->assertFormSet(['publish_until' => null])
        ->assertActionDoesNotExist($resetUntil);
});
