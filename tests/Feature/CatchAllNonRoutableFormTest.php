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
