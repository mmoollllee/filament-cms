<?php

/*
 * The contract of a blueprint's urlPathPrefix: the prefix owns everything but the
 * record's last segment, wherever the record sits in the tree and however it was created.
 *
 * It did not hold for anything created in the panel. `path` is a required form field, so
 * it always arrived filled, and PathGenerator's "path already set by the form" branch fired
 * before the prefix was ever applied — the prefix worked for programmatic writes only, and a
 * Ratgeber created through "Seiten" was stored on the root, permanently. The generator now
 * COMPOSES the prefixed path from the record's own segment instead of trusting the stored
 * string whole, which also makes the composition idempotent: re-running it on its own output
 * returns it unchanged, and running it on a drifted path returns the record to its prefix.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

it('stores a prefixed type under its prefix when it is created in the panel', function () {
    Livewire::test(CreateContent::class)
        ->fillForm([
            'content_type' => 'marketing.guide',
            'title' => 'Erste Hilfe',
            'path' => '/erste-hilfe',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // Before the fix this was "/erste-hilfe" — the type's prefix reached programmatic
    // writes only, so every record an editor created landed outside its own section.
    expect(Content::query()->where('title', 'Erste Hilfe')->sole()->path)
        ->toBe('/ratgeber/erste-hilfe');
});

it('composes the same path again when a prefixed record is re-saved', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Zweite Hilfe',
    ]);

    expect($guide->path)->toBe('/ratgeber/zweite-hilfe');

    // Idempotence is what makes the composition safe to run on every save: the last segment
    // of "/ratgeber/zweite-hilfe" is "zweite-hilfe", so composing it again yields itself.
    $guide->save();

    expect($guide->fresh()->path)->toBe('/ratgeber/zweite-hilfe');
});

it('returns a drifted prefixed record to its prefix on the next save', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Dritte Hilfe',
    ]);

    // The shape older data is in: something wrote a flattened path past the generator.
    $guide->forceFill(['path' => '/hilfe/dritte-hilfe'])->saveQuietly();

    $guide->fresh()->save();

    expect($guide->fresh()->path)->toBe('/ratgeber/dritte-hilfe');
});

it('lets the prefix win over the parent it is filed under', function () {
    $help = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Hilfe',
        'path' => '/hilfe',
    ]);

    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'parent_id' => $help->id,
        'title' => 'Vierte Hilfe',
    ]);

    expect($guide->path)->toBe('/ratgeber/vierte-hilfe')
        ->and($guide->parent_id)->toBe($help->id);
});
