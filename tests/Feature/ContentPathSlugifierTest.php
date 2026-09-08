<?php

/*
 * The "Pfad" field slugifies its own value on every blur (TitleWithSlugInput's
 * afterStateUpdated), and the value it holds is routinely a whole path — the parent
 * Select's rebase writes one in. Str::slug() drops "/" without replacement, so run over
 * the whole string it welds the segments together: "/ratgeber/erste-schritte" became
 * "/ratgebererste-schritte", got saved under that, and the page left its URL with no
 * error and no redirect.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    $this->parent = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ratgeber',
        'path' => '/ratgeber',
    ]);
});

it('keeps the segments apart when the field holds a whole path', function () {
    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->parent->id,
        'title' => 'Erste Schritte',
        'path' => '/ratgeber/erste-schritte',
    ]);

    // Re-typing the path the field already shows — the shape every nested page is in.
    Livewire::test(EditContent::class, ['record' => $child->getRouteKey()])
        ->fillForm(['path' => '/ratgeber/erste-schritte'])
        ->assertFormSet(['path' => '/ratgeber/erste-schritte'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($child->fresh()->path)->toBe('/ratgeber/erste-schritte');
});

it('still slugifies each segment', function () {
    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->parent->id,
        'title' => 'Erste Schritte',
        'path' => '/ratgeber/erste-schritte',
    ]);

    Livewire::test(EditContent::class, ['record' => $child->getRouteKey()])
        ->fillForm(['path' => '/Ratgeber/Zweite Schritte!'])
        ->assertFormSet(['path' => '/ratgeber/zweite-schritte']);
});
