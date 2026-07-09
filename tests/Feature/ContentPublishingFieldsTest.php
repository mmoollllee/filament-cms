<?php

/*
 * The "Sichtbarkeit" section's `status` field is virtual (no DB column): it is
 * derived from the publishing window (publish_from / publish_until). Filament
 * only applies ->default() when CREATING, so the initial value on an EDIT form
 * must come from ->formatStateUsing() instead — otherwise an already-published
 * page would load showing "Entwurf". These tests pin that hydration for every
 * status, plus the create-time default and the bidirectional reactive update.
 */

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
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
function makeContent(Tenant $tenant, string $path, ?Carbon\CarbonInterface $from, ?Carbon\CarbonInterface $until = null): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Fixture '.$path,
        'path' => $path,
        'visibility' => \Mmoollllee\Cms\Enums\ContentVisibility::Public,
        'publish_from' => $from,
        'publish_until' => $until,
    ]);
}

it('hydrates the status field from an already published record on edit', function () {
    // Seeded home page: publish_from a week ago, no publish_until → published.
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    expect($home->status()->value)->toBe('published');

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        ->assertFormSet(['status' => 'published']);
});

it('hydrates the status field for a draft record on edit', function () {
    $record = makeContent($this->tenant, '/fixture-draft', from: null);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['status' => 'draft']);
});

it('hydrates the status field for a scheduled record on edit', function () {
    $record = makeContent($this->tenant, '/fixture-scheduled', from: now()->addWeek());

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['status' => 'scheduled']);
});

it('hydrates the status field for an expired record on edit', function () {
    $record = makeContent($this->tenant, '/fixture-expired', from: now()->subWeek(), until: now()->subDay());

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['status' => 'expired']);
});

it('defaults the status field to draft on the create form', function () {
    Livewire::test(CreateContent::class)
        ->assertOk()
        ->assertFormSet(['status' => 'draft']);
});

it('recomputes the status when the publishing window changes (bidirectional)', function () {
    $record = makeContent($this->tenant, '/fixture-bidi', from: null);

    Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertFormSet(['status' => 'draft'])
        // Setting publish_from into the future fires the field's afterStateUpdated
        // (must use ->set(), since ->fillForm() disables state-update hooks) which
        // recomputes status → scheduled.
        ->set('data.publish_from', now()->addWeek()->format('Y-m-d H:i:s'))
        ->assertFormSet(['status' => 'scheduled']);
});

it('shows the reset hint action only when publish_from is dirty and reverts it', function () {
    // Home page: publish_from a week ago, no publish_until → published.
    $home = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/')->firstOrFail();

    $resetFrom = TestAction::make('reset_publish_from')->schemaComponent('publish_from');

    Livewire::test(EditContent::class, ['record' => $home->getKey()])
        ->assertOk()
        // Pristine load: value matches the saved record → the reset action is hidden.
        // A hidden hint action is not resolvable, so "does not exist" is the assertion.
        ->assertActionDoesNotExist($resetFrom)
        // Change the field → status recomputes and the reset action appears.
        ->set('data.publish_from', now()->addWeek()->format('Y-m-d H:i:s'))
        ->assertFormSet(['status' => 'scheduled'])
        ->assertActionVisible($resetFrom)
        // Click the reset button (the raw mountAction the UI dispatches while it is
        // visible): the value + status revert to the saved state, hiding it again.
        ->call('mountAction', 'reset_publish_from', [], ['schemaComponent' => 'form.publish_from'])
        ->assertFormSet(['status' => 'published'])
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
        ->assertFormSet(['status' => 'expired'])
        ->assertActionVisible($resetUntil)
        ->call('mountAction', 'reset_publish_until', [], ['schemaComponent' => 'form.publish_until'])
        // Saved value was empty → reverts to null, page is published again.
        ->assertFormSet(['status' => 'published', 'publish_until' => null])
        ->assertActionDoesNotExist($resetUntil);
});
