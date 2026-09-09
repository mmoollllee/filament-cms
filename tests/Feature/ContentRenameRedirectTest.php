<?php

/*
 * Renaming a page kills its address. Nothing else in the CMS catches that: the 404
 * pipeline only writes a redirect once a JS-capable visitor has hit the dead URL often
 * enough AND the fuzzy resolver is very confident — and it only gets that confident when
 * the last segment stayed the same, which is a move, never a rename.
 *
 * So the address is KEPT, by the model, without asking: a path moves from far more places
 * than a form (revision restore, Duplizieren, reorder, import, console), and none of those
 * has anyone to ask. The one genuine decision left — taking an address back off a redirect
 * that still stands on it — is on the Pfad field, where the editor is already typing.
 */

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Routing\ContentRenameRedirects;
use Mmoollllee\Cms\Support\Routing\RedirectResolver;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    $this->page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ratgeber',
        'path' => '/ratgeber',
    ]);
});

/** Rename through the panel — no question in between any more. */
function renameThroughPanel(Content $page, string $to): void
{
    Livewire::test(EditContent::class, ['record' => $page->getRouteKey()])
        ->fillForm(['path' => $to])
        ->call('save')
        ->assertHasNoFormErrors();
}

it('keeps the old address alive when a page is renamed', function () {
    renameThroughPanel($this->page, '/hilfe');

    expect($this->page->fresh()->path)->toBe('/hilfe');

    $redirect = Redirect::query()->where('from_path', '/ratgeber')->sole();

    expect($redirect->to_content_id)->toBe($this->page->id)
        ->and($redirect->status_code)->toBe(301)
        ->and($redirect->origin)->toBe(RedirectOrigin::Rename)
        ->and($redirect->is_active)->toBeTrue()
        // Never a frozen URL: the redirect has to follow the page through the next rename.
        ->and($redirect->to_url)->toBeNull();
});

it('keeps an address that moves with no form in sight', function () {
    // The reason this lives in the model and not in the panel: a console command, an
    // import or a revision restore moves a path with nobody to ask.
    $this->page->update(['path' => '/hilfe']);

    expect(Redirect::query()->where('from_path', '/ratgeber')->value('to_content_id'))
        ->toBe($this->page->id);
});

it('writes nothing when the site has turned rename redirects off', function () {
    Config::set('cms.redirects.on_rename', 'never');

    renameThroughPanel($this->page, '/hilfe');

    expect($this->page->fresh()->path)->toBe('/hilfe')
        ->and(Redirect::query()->where('from_path', '/ratgeber')->exists())->toBeFalse();
});

it('keeps every address the subtree loses, not just the one that was edited', function () {
    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->page->id,
        'title' => 'Erste Schritte',
        'path' => '/ratgeber/erste-schritte',
    ]);

    $grandchild = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $child->id,
        'title' => 'Kontakt',
        'path' => '/ratgeber/erste-schritte/kontakt',
    ]);

    renameThroughPanel($this->page, '/hilfe');

    expect($child->fresh()->path)->toBe('/hilfe/erste-schritte')
        ->and($grandchild->fresh()->path)->toBe('/hilfe/erste-schritte/kontakt');

    // Each cascaded row writes its OWN old address from its own original value — no
    // separate subtree walk, and correct however deep the cascade went.
    expect(Redirect::query()->where('from_path', '/ratgeber/erste-schritte')->value('to_content_id'))
        ->toBe($child->id);
    expect(Redirect::query()->where('from_path', '/ratgeber/erste-schritte/kontakt')->value('to_content_id'))
        ->toBe($grandchild->id);
});

it('writes nothing when the path does not move', function () {
    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['title' => 'Ratgeber & Hilfe'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Redirect::query()->count())->toBe(0);
});

it('refuses to overwrite a redirect an admin curated', function () {
    $manual = Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/ratgeber',
        'to_url' => 'https://shop.example.test/beratung',
        'status_code' => 301,
        'origin' => RedirectOrigin::Manual,
        'notes' => 'Kampagne Q4',
    ]);

    renameThroughPanel($this->page, '/hilfe');

    $manual->refresh();

    // Its destination and its notes are the admin's decision; a rename may not repoint it.
    expect($manual->origin)->toBe(RedirectOrigin::Manual)
        ->and($manual->to_url)->toBe('https://shop.example.test/beratung')
        ->and($manual->to_content_id)->toBeNull()
        ->and($manual->notes)->toBe('Kampagne Q4');
});

it('brings a released address back when a page moves onto it again', function () {
    renameThroughPanel($this->page, '/hilfe');

    Redirect::query()->where('from_path', '/ratgeber')->sole()->delete();

    // A tombstone is not fillable back to life through updateOrCreate — the row would
    // return updated but still soft-deleted, invisible to the map, while the address
    // stayed dead.
    renameThroughPanel($this->page->fresh(), '/ratgeber');
    renameThroughPanel($this->page->fresh(), '/hilfe-neu');

    $revived = Redirect::query()->where('from_path', '/ratgeber')->sole();

    expect($revived->trashed())->toBeFalse()
        ->and($revived->is_active)->toBeTrue()
        ->and(app(RedirectResolver::class)->activeMap($this->tenant))->toHaveKey('/ratgeber');
});

it('clears its own forwarding address when a page moves back home', function () {
    renameThroughPanel($this->page, '/hilfe');

    expect(Redirect::query()->where('from_path', '/ratgeber')->exists())->toBeTrue();

    // A redirect answers BEFORE content does, so /ratgeber → this page would now loop the
    // page onto itself and make it unreachable at its own address. Nobody is asked: the
    // loop is never what anyone wanted.
    renameThroughPanel($this->page->fresh(), '/ratgeber');

    expect($this->page->fresh()->path)->toBe('/ratgeber')
        ->and(app(RedirectResolver::class)->activeMap($this->tenant))->not->toHaveKey('/ratgeber');
});

it('switches off the redirects pointing at a page that is deleted', function () {
    renameThroughPanel($this->page, '/hilfe');

    $this->page->fresh()->delete();

    $orphaned = Redirect::query()->where('from_path', '/ratgeber')->sole();

    // The delete strips `to_content_id` (nullOnDelete). Left active, the row would stay in
    // the list as a live redirect answering with nothing, and would go on holding
    // /ratgeber against the next page that wants it.
    expect($orphaned->is_active)->toBeFalse();
});

it('does not take an address away from a page that already moved onto it', function () {
    $other = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Beratung',
        'path' => '/beratung',
    ]);

    // Two moves in one request: /beratung is vacated and immediately taken. Writing a
    // redirect for the vacated address would shadow the page that now lives there.
    $other->update(['path' => '/beratung-neu']);
    Redirect::query()->where('from_path', '/beratung')->delete();

    $this->page->fresh()->update(['path' => '/beratung']);
    $other->fresh()->update(['path' => '/beratung']);
})->throws(ValidationException::class);

it('reports a redirect standing on the address the form would store', function () {
    renameThroughPanel($this->page, '/hilfe');

    Livewire::test(CreateContent::class)
        ->fillForm([
            'content_type' => 'default.page',
            'title' => 'Ratgeber neu',
            'path' => '/ratgeber',
        ])
        ->call('create')
        // Not a dialog between the editor and the save: the address is taken, and that is
        // said on the field that holds it, exactly like a page holding it would be.
        ->assertHasFormErrors(['path']);

    expect(Content::query()->where('title', 'Ratgeber neu')->exists())->toBeFalse();
});

it('hands the address back through the takeover action beside the field', function () {
    renameThroughPanel($this->page, '/hilfe');

    $redirect = Redirect::query()->where('from_path', '/ratgeber')->sole();

    Livewire::test(CreateContent::class)
        ->fillForm([
            'content_type' => 'default.page',
            'title' => 'Ratgeber neu',
            'path' => '/ratgeber',
        ])
        ->callAction(TestAction::make('takeOverAddress')->schemaComponent('take-over-address'))
        ->call('create')
        ->assertHasNoFormErrors();

    // Released, not erased: the unique index spans trashed rows, so the row stays a
    // tombstone and the 404 resolver will not put a redirect back on this path by itself.
    expect(Redirect::query()->whereKey($redirect->id)->exists())->toBeFalse()
        ->and(Redirect::withTrashed()->whereKey($redirect->id)->exists())->toBeTrue();

    expect(Content::query()->where('path', '/ratgeber')->where('title', 'Ratgeber neu')->count())->toBe(1);
});

it('leaves a standing redirect shadowing the page, as redirection.me does', function () {
    renameThroughPanel($this->page, '/hilfe');

    // The takeover exists precisely BECAUSE a redirect wins over content
    // (ResolveActiveRedirects resolves before the content lookup, by design). Not using it
    // has to leave that intact rather than be quietly overruled by the resolver.
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Neuer Ratgeber',
        'path' => '/ratgeber',
    ]);

    expect(app(RedirectResolver::class)->activeMap($this->tenant))
        ->toHaveKey('/ratgeber');
});

it('keeps a live page saveable when a redirect already shadows it', function () {
    // A redirect winning over content is documented parity, not a broken state — so a page
    // sitting on a shadowed address must stay editable. Reporting it on every validation
    // would make the page unsaveable and offer deleting somebody's curated row as the cure.
    Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/ratgeber',
        'to_url' => 'https://shop.example.test/beratung',
        'status_code' => 301,
        'origin' => RedirectOrigin::Manual,
        'notes' => 'Kampagne Q4',
    ]);

    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['title' => 'Ratgeber & Hilfe'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->page->fresh()->title)->toBe('Ratgeber & Hilfe');
});

it('keeps the rename origin when an automatic row held the address first', function () {
    // The model promotes an automatic row to Manual the moment a human touches it, and
    // from its point of view a rename IS a human. Left unguarded it would overwrite the
    // Rename origin, and write()'s own curated-row guard would then refuse this address
    // forever — the page's next move would leave nothing behind.
    Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/ratgeber',
        'to_content_id' => $this->page->id,
        'status_code' => 302,
        'origin' => RedirectOrigin::Automatic,
        'is_active' => false,
    ]);

    renameThroughPanel($this->page, '/hilfe');

    $redirect = Redirect::withTrashed()->where('from_path', '/ratgeber')->sole();

    expect($redirect->origin)->toBe(RedirectOrigin::Rename)
        ->and($redirect->status_code)->toBe(301);

    // …and the address is still ours to update on the next move.
    renameThroughPanel($this->page->fresh(), '/beratung');

    expect(Redirect::query()->where('from_path', '/hilfe')->exists())->toBeTrue();
});

it('leaves a rename row an admin has since curated alone', function () {
    renameThroughPanel($this->page, '/hilfe');

    $redirect = Redirect::query()->where('from_path', '/ratgeber')->sole();
    $redirect->forceFill(['to_content_id' => null, 'to_url' => 'https://partner.example/angebot'])->saveQuietly();

    // Model-level: the panel would refuse the first move outright, because a curated
    // redirect on /ratgeber is exactly what addressIsFreeRule reports. An import or a
    // revision restore has no such guard, and that is the writer this protects.
    $this->page->fresh()->update(['path' => '/ratgeber']);
    $this->page->fresh()->update(['path' => '/beratung']);

    // A rename only ever writes to_content_id, so an external target can only have come
    // from a person — overwriting it would destroy the decision without a word.
    expect($redirect->fresh()->to_url)->toBe('https://partner.example/angebot')
        ->and($redirect->fresh()->to_content_id)->toBeNull();
});

it('refuses a move that would drag a subpage under a redirect', function () {
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->page->id,
        'title' => 'Team',
        'path' => '/ratgeber/team',
    ]);

    $other = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Woanders',
        'path' => '/woanders',
    ]);

    Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/hilfe/team',
        'to_content_id' => $other->id,
        'status_code' => 301,
        'origin' => RedirectOrigin::Manual,
    ]);

    // The cascade's writes are not the record the form validated, so without a subtree
    // walk this saved silently and left the child dead at its own address.
    Livewire::test(EditContent::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['path' => '/hilfe'])
        ->call('save')
        ->assertHasFormErrors(['path']);

    expect($this->page->fresh()->path)->toBe('/ratgeber');
});

it('hands a released row back switched off, so restoring it cannot revive a ghost', function () {
    renameThroughPanel($this->page, '/hilfe');

    $redirect = Redirect::query()->where('from_path', '/ratgeber')->sole();

    app(ContentRenameRedirects::class)->release($redirect);

    // The redirects table offers "Wiederherstellen"; a tombstone that kept is_active would
    // come back live, and by then its target may be long gone.
    expect(Redirect::withTrashed()->whereKey($redirect->id)->sole()->is_active)->toBeFalse();
});

it('writes nothing at all when the redirect subsystem is switched off', function () {
    Config::set('cms.redirects.enabled', false);

    // The table is published, not loaded, so an app that never published it has none —
    // reaching it before the switch would turn every rename into a QueryException.
    DB::table('redirects')->delete();

    renameThroughPanel($this->page, '/hilfe');

    expect($this->page->fresh()->path)->toBe('/hilfe')
        ->and(Redirect::withTrashed()->count())->toBe(0);
});
