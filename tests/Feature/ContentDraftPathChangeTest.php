<?php

/*
 * "Entwurf speichern" stashes the form state into the `draft` column with the query
 * builder — no model save, so no path generation, no collision guard and no redirect.
 * "Änderungen anwenden" runs the full save and does all three. Everything the path
 * machinery writes therefore has to wait for the applied save, or a stash that is never
 * applied leaves the site changed behind the editor's back.
 *
 * Validation is the exception, and deliberately so: saveDraft() validates the form exactly
 * like save(), so a stash cannot quietly hold a path that could never be applied.
 */

use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    // Published: the draft workflow only engages for a record whose applied row is live —
    // that is the only state where a stash protects anything.
    $this->page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ratgeber',
        'path' => '/ratgeber',
        'publish_from' => now()->subDay(),
    ]);
});

it('leaves the live address alone while a new path is only stashed', function () {
    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['path' => '/hilfe'])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    expect($this->page->fresh()->path)->toBe('/ratgeber')
        ->and(Redirect::query()->count())->toBe(0);
});

it('keeps the old address once the stashed path is applied', function () {
    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['path' => '/hilfe'])
        ->call('saveDraft');

    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->page->fresh()->path)->toBe('/hilfe')
        ->and(Redirect::query()->where('from_path', '/ratgeber')->value('to_content_id'))
        ->toBe($this->page->id);
});

it('refuses to stash a path that could never be applied', function () {
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Besetzt',
        'path' => '/besetzt',
    ]);

    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['path' => '/besetzt'])
        ->call('saveDraft')
        ->assertHasFormErrors(['path']);

    expect($this->page->fresh()->hasDraft())->toBeFalse();
});

it('does not free an address for a takeover that is only stashed', function () {
    $moved = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Umgezogen',
        'path' => '/umgezogen',
        'publish_from' => now()->subDay(),
    ]);

    Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/hilfe',
        'to_content_id' => $moved->id,
        'status_code' => 301,
        'origin' => RedirectOrigin::Rename,
    ]);

    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['path' => '/hilfe'])
        ->callAction(TestAction::make('takeOverAddress')->schemaComponent('take-over-address'))
        // The consent settles the field so the stash is allowed through…
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    // …but /hilfe still forwards, because nothing has moved onto it.
    expect($this->page->fresh()->path)->toBe('/ratgeber')
        ->and(app(RedirectResolver::class)->activeMap($this->tenant))->toHaveKey('/hilfe');
});

it('frees the address on the save that actually moves the page there', function () {
    $moved = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Umgezogen',
        'path' => '/umgezogen',
        'publish_from' => now()->subDay(),
    ]);

    $redirect = Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/hilfe',
        'to_content_id' => $moved->id,
        'status_code' => 301,
        'origin' => RedirectOrigin::Rename,
    ]);

    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['path' => '/hilfe'])
        ->callAction(TestAction::make('takeOverAddress')->schemaComponent('take-over-address'))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->page->fresh()->path)->toBe('/hilfe')
        ->and(Redirect::query()->whereKey($redirect->id)->exists())->toBeFalse()
        ->and(Redirect::withTrashed()->whereKey($redirect->id)->exists())->toBeTrue();
});

it('revokes the consent when the editor types a different path after giving it', function () {
    $moved = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Umgezogen',
        'path' => '/umgezogen',
        'publish_from' => now()->subDay(),
    ]);

    Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/hilfe',
        'to_content_id' => $moved->id,
        'status_code' => 301,
        'origin' => RedirectOrigin::Rename,
    ]);

    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['path' => '/hilfe'])
        ->callAction(TestAction::make('takeOverAddress')->schemaComponent('take-over-address'))
        ->fillForm(['path' => '/beratung'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The consent named /hilfe; the page went somewhere else, so /hilfe keeps forwarding.
    expect($this->page->fresh()->path)->toBe('/beratung')
        ->and(app(RedirectResolver::class)->activeMap($this->tenant))->toHaveKey('/hilfe');
});

it('does not free an address for a preview of a page nobody can see yet', function () {
    // An unpublished record has nothing live to protect, so the draft workflow is off and
    // "Vorschau" persists through the normal save. Destroying a working redirect for a look
    // at a page guests cannot reach is not what the click asked for.
    $unpublished = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Bald',
        'path' => '/bald',
    ]);

    $moved = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Umgezogen',
        'path' => '/umgezogen',
        'publish_from' => now()->subDay(),
    ]);

    Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/hilfe',
        'to_content_id' => $moved->id,
        'status_code' => 301,
        'origin' => RedirectOrigin::Rename,
    ]);

    Livewire::test(EditContent::class, ['record' => $unpublished->getRouteKey()])
        ->fillForm(['path' => '/hilfe'])
        ->callAction(TestAction::make('takeOverAddress')->schemaComponent('take-over-address'))
        ->call('saveForPreview')
        ->assertHasNoFormErrors();

    expect(app(RedirectResolver::class)->activeMap($this->tenant))->toHaveKey('/hilfe');
});

it('spends the consent on one release, not on every later save of the same form', function () {
    $moved = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Umgezogen',
        'path' => '/umgezogen',
        'publish_from' => now()->subDay(),
    ]);

    Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/hilfe',
        'to_content_id' => $moved->id,
        'status_code' => 301,
        'origin' => RedirectOrigin::Rename,
    ]);

    $form = Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['path' => '/hilfe'])
        ->callAction(TestAction::make('takeOverAddress')->schemaComponent('take-over-address'))
        ->call('save')
        ->assertHasNoFormErrors();

    // The consent is spent the moment it is acted on, not left as a standing licence.
    expect($form->get('data.'.TenantScopedContentResource::TAKEOVER_CONSENT_FIELD))->toBeNull();

    // An admin brings the address back and curates it (the table offers exactly this).
    $curated = Redirect::withTrashed()->where('from_path', '/hilfe')->sole();
    $curated->restore();
    $curated->forceFill([
        'is_active' => true,
        'to_content_id' => null,
        'to_url' => 'https://partner.example/angebot',
        'origin' => RedirectOrigin::Manual,
        'notes' => 'Kampagne',
    ])->saveQuietly();

    // The same still-open form saves again, changing only the title. A consent that
    // survived would delete a row the editor was never shown.
    $form->fillForm(['title' => 'Kontakt neu'])->call('save');

    expect(Redirect::query()->whereKey($curated->id)->exists())->toBeTrue();
});
