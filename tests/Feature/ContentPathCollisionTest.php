<?php

/*
 * `contents` carries a unique index on (tenant_id, path), but the path the form
 * validates is not the path that gets stored: the saving hook rebases it under
 * the selected parent. Two failures came out of that gap and are pinned here:
 *
 *  1. A record created in the panel used to be stored with the RAW form path.
 *     AssignsCurrentTenant ran on `creating`, i.e. after `saving`, so
 *     GeneratesPathAndSlug looked the blueprint up with a null site_key, found
 *     nothing and left the path untouched — a wrong URL until the next save.
 *  2. That next save then regenerated the parent-driven path, and when a sibling
 *     already owned it the unique index answered with an uncaught
 *     UniqueConstraintViolationException: a 500 in the panel, the edit lost, and
 *     the record permanently unsaveable because every retry produced the same
 *     path. Production sat on that for one record over 18 attempts.
 */

use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    $this->category = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ratgeber',
        'path' => '/ratgeber',
    ]);
});

it('stores the parent-driven path for a record created in the panel', function () {
    Livewire::test(CreateContent::class)
        ->fillForm([
            'content_type' => 'default.page',
            'title' => 'Erste Schritte',
            'path' => '/erste-schritte',
            'parent_id' => $this->category->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Content::query()->where('title', 'Erste Schritte')->sole();

    // Before the fix this was "/erste-schritte": the tenant was assigned one
    // hook too late, so the blueprint lookup came back null and PathGenerator
    // returned the form's own value.
    expect($created->path)->toBe('/ratgeber/erste-schritte')
        ->and($created->tenant_id)->toBe($this->tenant->id);
});

it('rejects a new record whose rebased path a sibling already owns', function () {
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->category->id,
        'title' => 'Erste Schritte',
        'path' => '/ratgeber/erste-schritte',
    ]);

    // "/erste-schritte" itself is free — only the rebased path collides, which
    // the field's own unique rule cannot see.
    Livewire::test(CreateContent::class)
        ->fillForm([
            'content_type' => 'default.page',
            'title' => 'Erste Schritte ',
            'path' => '/erste-schritte',
            'parent_id' => $this->category->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['path']);

    expect(Content::query()->where('title', 'Erste Schritte ')->exists())->toBeFalse();
});

it('rejects an edit that moves a record onto a sibling path', function () {
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->category->id,
        'title' => 'Erste Schritte',
        'path' => '/ratgeber/erste-schritte',
    ]);

    $stranded = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Erste Schritte ',
        'path' => '/erste-schritte',
    ]);

    // The shape production was stuck on: the record already sits under the
    // category while its stored path is still the pre-move one, so every save
    // regenerates the sibling's path.
    $stranded->forceFill(['parent_id' => $this->category->id])->saveQuietly();

    Livewire::test(EditContent::class, ['record' => $stranded->getRouteKey()])
        ->call('save')
        ->assertHasFormErrors(['path']);

    expect($stranded->fresh()->path)->toBe('/erste-schritte');
});

it('leaves a parentless record alone, whose full typed path is kept', function () {
    // parent_id is nullOnDelete, so deleting a parent orphans its children while
    // they keep a multi-segment path. PathGenerator stores such a path verbatim;
    // a rule that flattened it to its last segment would reject this save on the
    // unrelated top-level page below and strand the record for good.
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt',
        'path' => '/kontakt',
    ]);

    $orphan = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt',
        'path' => '/service/kontakt',
    ]);

    Livewire::test(EditContent::class, ['record' => $orphan->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($orphan->fresh()->path)->toBe('/service/kontakt');
});

it('refuses a rename that would cascade a child onto a taken path', function () {
    // P at /alt with child C at /alt/kontakt; an unrelated record already owns
    // /neu/kontakt. /neu itself is free, so nothing about P's own path objects — the
    // collision only appears once the saved hook re-saves C onto the new prefix, one
    // write past everything the form validated.
    $parent = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Alt',
        'path' => '/alt',
    ]);

    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $parent->id,
        'title' => 'Kontakt',
        'path' => '/alt/kontakt',
    ]);

    $occupant = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt (neu)',
        'path' => '/neu/kontakt',
    ]);

    $parent->path = '/neu';

    expect(fn () => $parent->save())->toThrow(ValidationException::class);

    // Nothing moved: not the parent whose own path was free, and not the child the
    // cascade had already started on before the guard existed.
    expect($parent->fresh()->path)->toBe('/alt')
        ->and($child->fresh()->path)->toBe('/alt/kontakt')
        ->and($occupant->fresh()->path)->toBe('/neu/kontakt');
});

it('refuses a rename that would cascade a grandchild onto a taken path', function () {
    // The guard has to walk the whole subtree, not just the first level.
    $parent = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Alt',
        'path' => '/alt',
    ]);

    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $parent->id,
        'title' => 'Service',
        'path' => '/alt/service',
    ]);

    $grandchild = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $child->id,
        'title' => 'Kontakt',
        'path' => '/alt/service/kontakt',
    ]);

    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt (neu)',
        'path' => '/neu/service/kontakt',
    ]);

    $parent->path = '/neu';

    expect(fn () => $parent->save())->toThrow(ValidationException::class);

    expect($parent->fresh()->path)->toBe('/alt')
        ->and($child->fresh()->path)->toBe('/alt/service')
        ->and($grandchild->fresh()->path)->toBe('/alt/service/kontakt');
});

it('moves the whole subtree when every regenerated path is free', function () {
    $parent = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Alt',
        'path' => '/alt',
    ]);

    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $parent->id,
        'title' => 'Service',
        'path' => '/alt/service',
    ]);

    $grandchild = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $child->id,
        'title' => 'Kontakt',
        'path' => '/alt/service/kontakt',
    ]);

    $parent->path = '/neu';
    $parent->save();

    expect($parent->fresh()->path)->toBe('/neu')
        ->and($child->fresh()->path)->toBe('/neu/service')
        ->and($grandchild->fresh()->path)->toBe('/neu/service/kontakt');
});

it('surfaces the subtree collision in the panel instead of a 500', function () {
    // The reported symptom was an uncaught exception: a Fehlerseite with the edit
    // gone. The editor has to get an error they can act on, and the tree has to be
    // exactly as they left it.
    $parent = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Alt',
        'path' => '/alt',
    ]);

    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $parent->id,
        'title' => 'Kontakt',
        'path' => '/alt/kontakt',
    ]);

    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt (neu)',
        'path' => '/neu/kontakt',
    ]);

    // On the Pfad field, not just somewhere in the component's error bag — an error
    // keyed to nothing the form renders would leave Speichern looking like a no-op.
    Livewire::test(EditContent::class, ['record' => $parent->getRouteKey()])
        ->fillForm(['path' => '/neu'])
        ->call('save')
        ->assertHasFormErrors(['path']);

    expect($parent->fresh()->path)->toBe('/alt')
        ->and($child->fresh()->path)->toBe('/alt/kontakt');
});

it('refuses a duplicate created outside the form', function () {
    // The Duplizieren action, imports, seeders and console commands never touch the
    // form rule; the guard sits in the saving hook so it covers them too.
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt',
        'path' => '/kontakt',
    ]);

    expect(fn () => Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt',
        'path' => '/kontakt',
    ]))->toThrow(ValidationException::class);

    expect(Content::query()->where('tenant_id', $this->tenant->id)->where('path', '/kontakt')->count())->toBe(1);
});

it('leaves a record whose rebased path is free alone', function () {
    $stranded = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Zweite Schritte',
        'path' => '/zweite-schritte',
    ]);

    $stranded->forceFill(['parent_id' => $this->category->id])->saveQuietly();

    Livewire::test(EditContent::class, ['record' => $stranded->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($stranded->fresh()->path)->toBe('/ratgeber/zweite-schritte');
});

it('leaves a prefixed type alone, whose path its parent never rebases', function () {
    // A blueprint with a urlPathPrefix keeps its type-based path wherever it sits in
    // the tree — PathGenerator skips parent-driven nesting for it entirely. The rule
    // used to re-derive the composition itself instead of asking the generator, so it
    // checked "<parent path>/<segment>" — a path this record never occupies. Here the
    // decoy owns exactly that phantom path, so a rule that still composed by hand
    // would reject the save and leave the record unsaveable for good.
    $help = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Hilfe',
        'path' => '/hilfe',
    ]);

    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $help->id,
        'title' => 'Zweite Schritte',
        'path' => '/hilfe/zweite-schritte',
    ]);

    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'parent_id' => $help->id,
        'title' => 'Zweite Schritte',
        'path' => '/ratgeber/zweite-schritte',
    ]);

    // The prefix wins over the hierarchy on write, too: the parent leaves the path be.
    expect($guide->fresh()->path)->toBe('/ratgeber/zweite-schritte');

    Livewire::test(EditContent::class, ['record' => $guide->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($guide->fresh()->path)->toBe('/ratgeber/zweite-schritte');
});
