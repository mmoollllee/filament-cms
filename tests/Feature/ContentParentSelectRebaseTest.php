<?php

/*
 * Picking an "Übergeordnete Seite" rewrites the Pfad field on the spot, so the editor
 * sees the resulting URL before saving. That preview is composed by
 * TenantScopedContentResource::rebasePathUnderParent(), which knows nothing about
 * blueprints — while PathGenerator, which decides what is actually STORED, nests under
 * the parent only for types WITHOUT a urlPathPrefix.
 *
 * For a prefixed type the two used to disagree, and the form won: the field was rewritten
 * to "<parent path>/<segment>", the generator kept that value verbatim (its "path already
 * set by the form" branch), and the record was stored without its prefix. Nothing failed
 * loudly, and it never healed — every later save read the flattened path back as the
 * typed value.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    $this->help = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Hilfe',
        'path' => '/hilfe',
    ]);
});

it('rebases the path under the chosen parent for a type without a prefix', function () {
    // The behaviour the guard must NOT break: pages nest, so the parent owns the prefix
    // and the record only keeps its last segment.
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Erste Schritte',
        'path' => '/erste-schritte',
    ]);

    Livewire::test(EditContent::class, ['record' => $page->getRouteKey()])
        ->fillForm(['parent_id' => $this->help->id])
        ->assertFormSet(['path' => '/hilfe/erste-schritte'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()->path)->toBe('/hilfe/erste-schritte');
});

it('leaves the path alone when the chosen parent cannot move a prefixed type', function () {
    // marketing.guide carries urlPathPrefix "/ratgeber/": the prefix wins over the
    // hierarchy, so the record stays at /ratgeber/… no matter which parent it is filed
    // under. Before the guard the field was rewritten to "/hilfe/zweite-schritte" and
    // that is what got stored.
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Zweite Schritte',
        'path' => '/ratgeber/zweite-schritte',
    ]);

    Livewire::test(EditContent::class, ['record' => $guide->getRouteKey()])
        ->fillForm(['parent_id' => $this->help->id])
        ->assertFormSet(['path' => '/ratgeber/zweite-schritte'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($guide->fresh()->path)->toBe('/ratgeber/zweite-schritte')
        ->and($guide->fresh()->parent_id)->toBe($this->help->id);

    // …and it stays put: a later save must not read a drifted path back as the typed one.
    $guide->fresh()->save();

    expect($guide->fresh()->path)->toBe('/ratgeber/zweite-schritte');
});

it('moves a record to the root when the parent is cleared', function () {
    // Clearing the parent is the one case where the form authors rather than previews:
    // the editor is moving the record out of the tree, so the field offers the bare
    // segment — and the generator, which owns nothing of a parentless non-prefixed path,
    // confirms it verbatim.
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->help->id,
        'title' => 'Erste Schritte',
        'path' => '/hilfe/erste-schritte',
    ]);

    Livewire::test(EditContent::class, ['record' => $page->getRouteKey()])
        ->fillForm(['parent_id' => null])
        ->assertFormSet(['path' => '/erste-schritte'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()->path)->toBe('/erste-schritte')
        ->and($page->fresh()->parent_id)->toBeNull();
});
