<?php

/*
 * The multi-type catch-all resolves no static blueprint, so its title/slug
 * input historically ALWAYS rendered the routable "Pfad" variant — even for a
 * selected non-routable type (marketing.note): the tenant-unique slug was not
 * editable at all, and a required path was demanded for records that never
 * have one. The input now mirrors the single-type behavior REACTIVELY on the
 * selected content type: slug-only for non-routable types, path otherwise.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

it('edits a non-routable type on the catch-all via the slug-only input', function () {
    $note = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.note',
        'title' => 'Notiz',
        'slug' => 'alte-notiz',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
    ]);

    Livewire::test(EditContent::class, ['record' => $note->getKey()])
        ->assertOk()
        ->fillForm([
            'title' => 'Notiz umbenannt',
            'slug' => 'neue-notiz',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $note->fresh();

    expect($fresh->title)->toBe('Notiz umbenannt')
        ->and($fresh->slug)->toBe('neue-notiz')
        ->and($fresh->path)->toBeNull();
});

it('keeps the routable path input working on the catch-all', function () {
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Seite',
        'path' => '/alte-seite',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
    ]);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        ->fillForm([
            'title' => 'Seite umbenannt',
            'path' => '/neue-seite',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $page->fresh();

    expect($fresh->title)->toBe('Seite umbenannt')
        ->and($fresh->path)->toBe('/neue-seite');
});

it('creates a non-routable type through the ?type= deep-link', function () {
    // The deep-link is the ONLY way to reach a type the Seiten-Typ select does not offer,
    // and the field it pins is chosen while the form tree is built. A Livewire update
    // rebuilds that tree on a request without the query string, so a choice made from the
    // query string alone flipped the pinned Hidden field to the Select between the first
    // render and the save — and the Select rejected the very type the link had pinned.
    Livewire::withQueryParams(['type' => 'marketing.note'])
        ->test(CreateContent::class)
        ->assertFormSet(['content_type' => 'marketing.note'])
        ->fillForm([
            'title' => 'Zweite Notiz',
            'slug' => 'zweite-notiz',
            'path' => '/zweite-notiz',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $note = Content::query()->where('title', 'Zweite Notiz')->sole();

    expect($note->content_type)->toBe('marketing.note')
        ->and($note->slug)->toBe('zweite-notiz')
        ->and($note->path)->toBeNull();
});

it('refuses a non-routable slug the tenant already uses', function () {
    // The slug is all a non-routable record has, and the tenant-unique rule guarding it
    // used to share the path field's parameters — whose switch turns the rule off whenever
    // the typed value is not the stored path. On the catch-all, a non-routable record's
    // path state is filled while its stored path is null, so the switch fired every time
    // and two records could take the same slug in silence.
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.note',
        'title' => 'Notiz',
        'slug' => 'belegt',
    ]);

    Livewire::withQueryParams(['type' => 'marketing.note'])
        ->test(CreateContent::class)
        ->fillForm([
            'title' => 'Zweite Notiz',
            'slug' => 'belegt',
            'path' => '/zweite-notiz',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Content::query()->where('tenant_id', $this->tenant->id)->where('slug', 'belegt')->count())->toBe(1);
});
