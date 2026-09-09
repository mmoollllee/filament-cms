<?php

/*
 * `parent_id` reaches the database validated by nothing but the Select's option list,
 * and an option list is a UI affordance. Every tree walk therefore has to say which
 * tenant it belongs to, and the form has to refuse a foreign parent outright — otherwise
 * one tenant's record inherits another's URL structure, its rename cascade saves rows it
 * does not own, and its collision message quotes a foreign page title back at the editor.
 *
 * `parent_id` is also the only edge in the tree and nothing in the schema stops it
 * pointing back into the chain, so both walks need a cycle brake — the ancestor walk
 * most of all, because ContentResolver falls back to running it per row on every 404.
 */

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Support\Content\PathGenerator;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    // The seeder's second tenant — a real neighbour, not a fixture invented for this.
    $this->foreign = Tenant::query()->where('site_key', 'acme')->sole();

    $this->foreignParent = Content::create([
        'tenant_id' => $this->foreign->id,
        'content_type' => 'default.page',
        'title' => 'Fremde Seite',
        'path' => '/fremd',
    ]);
});

// Filament derives an `in` rule from the Select's options, and getParentOptions() is
// tenant-scoped — so the panel already refuses this and needs no rule of its own. Pinned
// because it is a tenant boundary: replace that Select with a free-text field, or widen
// the options, and the guard is gone with no other sign.
it('refuses a parent that belongs to another tenant', function () {
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Eigene Seite',
        'path' => '/eigene-seite',
    ]);

    Livewire::test(EditContent::class, ['record' => $page->getRouteKey()])
        ->fillForm(['parent_id' => $this->foreignParent->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    expect($page->fresh()->parent_id)->toBeNull()
        ->and($page->fresh()->path)->toBe('/eigene-seite');
});

it('does not compose a path against a foreign parent', function () {
    // Written past the form, the way a crafted payload or an import would.
    $adopted = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Adoptiert',
        'path' => '/adoptiert',
    ]);

    DB::table('contents')->where('id', $adopted->id)->update(['parent_id' => $this->foreignParent->id]);

    // The generator must not hand this record the other tenant's prefix.
    expect($adopted->fresh()->resolvedPath())->toBe('/adoptiert');
});

it('does not cascade a rename into another tenant', function () {
    $parent = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Eltern',
        'path' => '/eltern',
    ]);

    $foreignChild = Content::create([
        'tenant_id' => $this->foreign->id,
        'content_type' => 'default.page',
        'title' => 'Fremdes Kind',
        'path' => '/fremdes-kind',
    ]);

    DB::table('contents')->where('id', $foreignChild->id)->update(['parent_id' => $parent->id]);

    $parent->path = '/eltern-neu';
    $parent->save();

    expect($parent->fresh()->path)->toBe('/eltern-neu')
        ->and($foreignChild->fresh()->path)->toBe('/fremdes-kind');
});

it('survives a cycle in parent_id instead of recursing forever', function () {
    $a = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'A',
        'path' => '/a',
    ]);

    $b = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $a->id,
        'title' => 'B',
        'path' => '/a/b',
    ]);

    // Only reachable past the hooks — which is exactly how a broken import leaves it.
    DB::table('contents')->where('id', $a->id)->update(['parent_id' => $b->id]);

    expect(app(PathGenerator::class)->generate($a->fresh()))->toBeString();
});
