<?php

/*
 * Leaving a content or fragment form through a manage link (<x-cms::manage-link>:
 * "Fragment bearbeiten", "… verwalten") while it has unsaved changes asks first:
 * save them (a draft on a live page, a normal save where no draft workflow
 * applies, a held-back record on a create page), leave without saving, or stay.
 * The link decides client-side whether to ask and mounts the page's
 * `confirmLeave` action with its URL — which the server only follows into the
 * panel (this host, or a tenant domain the user may enter).
 *
 * Workbench hosts: marketing is 127.0.0.1, acme is localhost — the host the
 * test requests run on.
 */

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Fragments\Pages\EditFragment;
use Mmoollllee\Cms\Filament\Support\UnsavedChanges;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

/** An edit page whose save hook refuses — the page swallows the halt. */
class ConfirmLeaveHaltingEditContent extends EditContent
{
    protected function beforeSave(): void
    {
        $this->halt();
    }
}

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $this->tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setTenant($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);

    // The marketing panel's domain — not the request host, so reachable only as a
    // tenant the (super)admin may enter.
    $this->target = 'http://127.0.0.1/panel/fragments';
});

function leavablePage(Tenant $tenant, array $attributes = []): Content
{
    return Content::factory()->for($tenant)->create([
        'content_type' => 'default.page',
        'title' => 'Vorher',
        'blocks' => [['type' => 'section', 'data' => ['active' => true, 'blocks' => [
            ['type' => 'fragment', 'data' => ['active' => true, 'slug' => 'cta']],
        ]]]],
        ...$attributes,
    ]);
}

it('wires the manage links of an edit page to the question', function () {
    $component = Livewire::test(EditContent::class, ['record' => leavablePage($this->tenant)->getKey()])
        ->assertSeeHtml('fi-cms-manage-link')
        ->assertSeeHtml("mountAction('confirmLeave'")
        // …gated on the same hash comparison the "Entwurf speichern" button uses…
        ->assertSeeHtml(e(UnsavedChanges::pristineJs()));

    // …against a stamp that matches the freshly filled form.
    expect($component->instance()->draftSavedDataHash)->toBe(UnsavedChanges::hash($component->instance()->data));
});

it('saves the changes as a draft, then follows the link', function () {
    $page = leavablePage($this->tenant);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->set('data.title', 'Nachher')
        ->callAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target]))
        ->assertHasNoErrors()
        ->assertRedirect($this->target);

    $page->refresh();

    // Stashed, not applied: the live page keeps its title.
    expect($page->title)->toBe('Vorher')
        ->and($page->draftData()['title'] ?? null)->toBe('Nachher');
});

it('leaves without saving when asked to', function () {
    $page = leavablePage($this->tenant);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->set('data.title', 'Nachher')
        ->callAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target, 'save' => false]))
        ->assertRedirect($this->target);

    $page->refresh();

    expect($page->title)->toBe('Vorher')
        ->and($page->draftData())->toBe([]);
});

it('saves normally where no draft workflow applies', function () {
    // Unpublished: nothing live to protect, so the page has no draft flow.
    $page = leavablePage($this->tenant, ['publish_from' => null]);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->set('data.title', 'Nachher')
        ->callAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target]))
        ->assertRedirect($this->target);

    expect($page->refresh()->title)->toBe('Nachher');
});

it('stays when a save hook halts the save', function () {
    $page = leavablePage($this->tenant, ['publish_from' => null]);

    $component = Livewire::test(ConfirmLeaveHaltingEditContent::class, ['record' => $page->getKey()])
        ->set('data.title', 'Nachher')
        ->callAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target]))
        ->assertNoRedirect();

    expect($page->refresh()->title)->toBe('Vorher');

    // The same answer keeps the "Vorschau" tab closed.
    $component->call('saveForPreview')->assertReturned(false);
});

it('stays on the page and shows the errors when the form is invalid', function () {
    $page = leavablePage($this->tenant);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->set('data.title', '')
        ->callAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target]))
        ->assertHasErrors(['data.title'])
        ->assertNoRedirect()
        // The modal closed, so the errors are visible on the form.
        ->assertSet('mountedActions', []);

    expect($page->refresh()->draftData())->toBe([]);
});

it('refuses a target outside the panel', function (string $url) {
    $page = leavablePage($this->tenant);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->set('data.title', 'Nachher')
        ->callAction(TestAction::make('confirmLeave')->arguments(['url' => $url]))
        ->assertNoRedirect();

    expect($page->refresh()->draftData())->toBe([]);
})->with([
    'another host' => 'https://evil.example/login',
    'protocol-relative' => '//evil.example/login',
    'backslash-relative' => '/\\evil.example/login',
    'credentials' => 'http://admin@127.0.0.1/panel/fragments',
    'javascript' => 'javascript:alert(1)',
]);

it('refuses a tenant domain the user may not enter', function () {
    $acme = Tenant::where('site_key', 'acme')->firstOrFail();

    $this->actingAs(User::where('email', 'admin-b@example.test')->firstOrFail());
    Filament::setTenant($acme);
    app(CurrentTenant::class)->set($acme);

    $component = Livewire::test(EditContent::class, ['record' => leavablePage($acme)->getKey()])
        ->set('data.title', 'Nachher');

    // Marketing's domain: not acme's admin's to enter.
    $component->callAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target, 'save' => false]))
        ->assertNoRedirect();

    // The panel's own host is.
    $component->callAction(TestAction::make('confirmLeave')->arguments(['url' => 'http://localhost/panel/fragments', 'save' => false]))
        ->assertRedirect('http://localhost/panel/fragments');
});

it('follows the link right away when the form turns out clean', function () {
    $page = leavablePage($this->tenant);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->mountAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target]))
        ->assertRedirect($this->target)
        ->assertSet('mountedActions', []);

    expect($page->refresh()->draftData())->toBe([]);
});

it('never asks when opened from the page URL', function () {
    $page = leavablePage($this->tenant);

    // What Filament's `?action=confirmLeave&actionArguments[url]=…` default action runs.
    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->set('data.title', 'Nachher')
        ->call('mountAction', 'confirmLeave', ['url' => $this->target], ['mountedFromUrl' => true])
        ->assertNoRedirect()
        ->assertSet('mountedActions', []);
});

it('asks on the fragment edit page as well', function () {
    $fragment = Fragment::where('slug', 'cta')->firstOrFail();

    Livewire::test(EditFragment::class, ['record' => $fragment->getKey()])
        ->set('data.title', 'Neuer Titel')
        ->callAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target]))
        ->assertRedirect($this->target);

    expect($fragment->refresh()->draftData()['title'] ?? null)->toBe('Neuer Titel');
});

it('creates the record held back before leaving a create page', function () {
    $component = Livewire::test(CreateContent::class);

    // A create page stamps its empty form, so the links can tell typed input from none.
    expect($component->instance()->draftSavedDataHash)->toBe(UnsavedChanges::hash($component->instance()->data));

    $component
        ->fillForm([
            'title' => 'Neue Seite',
            'path' => '/neue-seite',
            'is_published' => true,
            'publish_from' => now()->subHour()->format('Y-m-d H:i'),
        ])
        ->callAction(TestAction::make('confirmLeave')->arguments(['url' => $this->target]))
        ->assertHasNoFormErrors()
        ->assertRedirect($this->target);

    // "Unveröffentlicht anlegen": the record exists, the website is unchanged.
    $record = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/neue-seite')->firstOrFail();

    expect($record->title)->toBe('Neue Seite')
        ->and($record->publish_from)->toBeNull();
});
