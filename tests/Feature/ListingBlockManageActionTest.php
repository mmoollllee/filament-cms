<?php

/*
 * The listing block's "… verwalten" deep-link: below its backend preview, a
 * button links to the Filament resource that manages the listed content type.
 * It replaces the removed per-page "manage children" header action.
 *
 * Two layers:
 *  - ContentResourceLocator maps content_type → managing resource class.
 *  - The rendered edit form shows the button (with the resolved index URL) for a
 *    seeded listing block.
 */

use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\CreateContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ListContents;
use Mmoollllee\Cms\Support\Content\ContentResourceLocator;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\App\Sites\Marketing\Service\Resource as ServiceResource;
use Workbench\Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $this->tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setTenant($this->tenant);
    app(CurrentTenant::class)->set($this->tenant);
});

it('resolves a specialized site-extension resource for its content type', function () {
    expect(app(ContentResourceLocator::class)->resolve('marketing.service', $this->tenant))
        ->toBe(ServiceResource::class);
});

it('falls back to the catch-all resource for types no specialized resource claims', function () {
    // marketing.note has a blueprint but no dedicated resource → catch-all.
    expect(app(ContentResourceLocator::class)->resolve('marketing.note', $this->tenant))
        ->toBe(CatchAllContentResource::class);
});

it('returns null for an unmanaged type and for a null tenant', function () {
    expect(app(ContentResourceLocator::class)->resolve('does.not.exist', $this->tenant))->toBeNull()
        ->and(app(ContentResourceLocator::class)->resolve('marketing.service', null))->toBeNull();
});

it('renders the "… verwalten" button under a listing block preview', function () {
    // The seeded /blocks page nests a listing block (content_type marketing.service)
    // inside a section — the section's child builder renders previews, so the
    // listing preview (and its deep-link) appears.
    $blocksPage = Content::where('tenant_id', $this->tenant->getKey())->where('path', '/blocks')->firstOrFail();

    Livewire::test(EditContent::class, ['record' => $blocksPage->getKey()])
        ->assertOk()
        ->assertSeeHtml('fi-cms-listing-manage')
        ->assertSee('Services verwalten')
        // The deep-link scopes the destination list to the listed type.
        ->assertSeeHtml('type=marketing.service');
});

it('scopes a resource index to a managed ?type= and ignores unmanaged ones', function () {
    // marketing.note falls to the catch-all (no dedicated resource); a note record
    // plus the seeded default.page records make the catch-all list heterogeneous.
    Content::create([
        'tenant_id' => $this->tenant->getKey(),
        'content_type' => 'marketing.note',
        'title' => 'A note',
        'visibility' => ContentVisibility::Public,
    ]);

    request()->merge(['type' => 'marketing.note']);

    $types = CatchAllContentResource::getEloquentQuery()->pluck('content_type')->unique()->values()->all();

    expect(CatchAllContentResource::getRequestedContentType())->toBe('marketing.note')
        ->and($types)->toBe(['marketing.note']);

    // A type this resource does NOT manage (owned by ServiceResource) is ignored,
    // so it can never widen the resource beyond what it owns.
    request()->merge(['type' => 'marketing.service']);

    expect(CatchAllContentResource::getRequestedContentType())->toBeNull();
});

it('recovers the ?type= scope from the Referer on a Livewire update request', function () {
    // A Livewire table update (paginate/sort/search) POSTs to /livewire/update with no
    // query string; the scope must survive via the Referer, not silently widen.
    request()->headers->set('X-Livewire', 'true');
    request()->headers->set('referer', 'http://localhost/admin/contents?type=marketing.note');

    expect(CatchAllContentResource::getRequestedContentType())->toBe('marketing.note');
});

it('does not recover ?type= from a stale Referer on a normal navigation', function () {
    // No X-Livewire header → a fresh GET to an unscoped list must not inherit a stale
    // scope from wherever the user came from.
    request()->headers->set('referer', 'http://localhost/admin/contents?type=marketing.note');

    expect(CatchAllContentResource::getRequestedContentType())->toBeNull();
});

it('reflects the scoped type in the list heading', function () {
    Livewire::withQueryParams(['type' => 'marketing.note'])
        ->test(ListContents::class)
        ->assertOk()
        ->assertSee('Notes'); // Str::plural('Note'), the marketing.note plural label
});

it('carries ?type= through the create action of a type-scoped list', function () {
    Livewire::withQueryParams(['type' => 'marketing.note'])
        ->test(ListContents::class)
        ->assertOk()
        // The "… anlegen" button links to the create form pre-scoped to that type.
        ->assertSeeHtml('create?type=marketing.note')
        ->assertSee('Note anlegen'); // singular blueprint label, not "Seite anlegen"
});

it('pre-selects the requested ?type= as the create form default', function () {
    // marketing.note is non-routable → not offered in the type select, so it is
    // pinned via the Hidden content_type field. Without the ?type= default it would
    // fall back to default.page and the note could not be created from the UI at all.
    Livewire::withQueryParams(['type' => 'marketing.note'])
        ->test(CreateContent::class)
        ->assertOk()
        ->assertFormSet(['content_type' => 'marketing.note']);
});
