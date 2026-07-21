<?php

/*
 * Drafts ("Entwurf speichern") + preview mode ("Vorschau"):
 *
 * - HasDraft stashes form state in the `draft` column without touching the
 *   live columns; discard/clear round-trips.
 * - While PreviewMode is active, every retrieved Content/Fragment overlays its
 *   draft — pages, listings and fragments show pending changes everywhere.
 * - The mode is entered/left via ?preview=1/0, is session-sticky, and is only
 *   available to superadmins/members of the resolved tenant.
 * - Draft stashes never invalidate or poison the warm frontend caches.
 */

use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Preview\PreviewMode;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

function previewTenant(): Tenant
{
    return Tenant::factory()->create([
        'primary_domain' => '127.0.0.1',
        'site_key' => 'marketing',
    ]);
}

function previewPage(Tenant $tenant): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Live-Titel',
        'path' => '/preview-fixture',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'blocks' => [[
            'type' => 'section',
            'data' => ['blocks' => [[
                'type' => 'text',
                'data' => ['active' => true, 'heading' => 'Live-Fassung', 'content' => '<p>LIVE-INHALT</p>'],
            ]]],
        ]],
    ]);
}

/** Stash a draft that retitles the page and swaps the text block. */
function stashPreviewDraft(Content $page): void
{
    $page->stashDraft([
        'title' => 'Entwurfs-Titel',
        'blocks' => [[
            'type' => 'section',
            'data' => ['blocks' => [[
                'type' => 'text',
                'data' => ['active' => true, 'heading' => 'Entwurfs-Fassung', 'content' => '<p>ENTWURF-INHALT</p>'],
            ]]],
        ]],
    ]);
}

// -----------------------------------------------------------------------------
//  HasDraft model behavior
// -----------------------------------------------------------------------------

it('stashes a draft without touching the live columns', function () {
    $page = previewPage(previewTenant());

    stashPreviewDraft($page);

    $fresh = Content::query()->find($page->getKey());

    expect($fresh->title)->toBe('Live-Titel')
        ->and($fresh->hasDraft())->toBeTrue()
        ->and($fresh->draftData()['title'])->toBe('Entwurfs-Titel')
        ->and($fresh->draftSavedAt())->not->toBeNull();
});

it('discards a draft and keeps the applied values', function () {
    $page = previewPage(previewTenant());
    stashPreviewDraft($page);

    $page->discardDraft();

    $fresh = Content::query()->find($page->getKey());

    expect($fresh->hasDraft())->toBeFalse()
        ->and($fresh->title)->toBe('Live-Titel');
});

it('overlays the draft on retrieval only while preview mode is active', function () {
    $page = previewPage(previewTenant());
    stashPreviewDraft($page);

    expect(Content::query()->find($page->getKey()))
        ->title->toBe('Live-Titel');

    app(PreviewMode::class)->activate();

    expect(Content::query()->find($page->getKey()))
        ->title->toBe('Entwurfs-Titel');

    app(PreviewMode::class)->deactivate();
});

it('never persists overlaid draft values: the overlay is clean and stash/discard touch only the draft column', function () {
    $page = previewPage(previewTenant());
    stashPreviewDraft($page);

    app(PreviewMode::class)->activate();

    $overlaid = Content::query()->find($page->getKey());

    // syncOriginal hardening: an overlaid instance must never look dirty —
    // otherwise any save() would silently apply the draft to the live columns.
    expect($overlaid->title)->toBe('Entwurfs-Titel')
        ->and($overlaid->isDirty())->toBeFalse();

    // The panel-leak scenario (preview flag sticky during a panel Livewire
    // request): discarding via an overlaid instance must DISCARD, not apply.
    $overlaid->discardDraft();

    app(PreviewMode::class)->deactivate();

    $fresh = Content::query()->find($page->getKey());

    expect($fresh->title)->toBe('Live-Titel')
        ->and($fresh->hasDraft())->toBeFalse();

    // Same guarantee for stashing on an overlaid instance.
    stashPreviewDraft($fresh);
    app(PreviewMode::class)->activate();

    Content::query()->find($page->getKey())->stashDraft(['title' => 'Zweiter Entwurf']);

    app(PreviewMode::class)->deactivate();

    $after = Content::query()->find($page->getKey());

    expect($after->title)->toBe('Live-Titel')
        ->and($after->draftData()['title'])->toBe('Zweiter Entwurf');
});

it('cannot overwrite guarded attributes through a draft overlay', function () {
    $page = previewPage(previewTenant());

    $page->stashDraft(['title' => 'Entwurfs-Titel', 'id' => 999999]);

    app(PreviewMode::class)->activate();

    $overlaid = Content::query()->find($page->getKey());

    expect($overlaid->getKey())->toBe($page->getKey())
        ->and($overlaid->title)->toBe('Entwurfs-Titel');

    app(PreviewMode::class)->deactivate();
});

it('overlays fragment drafts in preview mode', function () {
    $tenant = previewTenant();

    $fragment = Fragment::create([
        'tenant_id' => $tenant->id,
        'title' => 'CTA',
        'slug' => 'cta',
        'blocks' => [['type' => 'text', 'data' => ['active' => true, 'content' => '<p>Live-CTA</p>']]],
    ]);

    $fragment->stashDraft([
        'title' => 'CTA',
        'slug' => 'cta',
        'blocks' => [['type' => 'text', 'data' => ['active' => true, 'content' => '<p>Entwurfs-CTA</p>']]],
    ]);

    expect(Fragment::resolveFragment($tenant, 'cta')?->blocks[0]['data']['content'])->toContain('Live-CTA');

    app(PreviewMode::class)->activate();
    // The per-request fragment collection cached before activation must not
    // leak into the preview lookup.
    Cache::store('array')->forget(CacheKeys::fragments($tenant->getKey()));

    expect(Fragment::resolveFragment($tenant, 'cta')?->blocks[0]['data']['content'])->toContain('Entwurfs-CTA');

    app(PreviewMode::class)->deactivate();
});

// -----------------------------------------------------------------------------
//  Preview mode over HTTP (?preview=1/0, session-sticky, authorization)
// -----------------------------------------------------------------------------

it('lets a superadmin enter and leave preview mode, sticky across requests', function () {
    $tenant = previewTenant();
    $page = previewPage($tenant);
    stashPreviewDraft($page);

    $this->actingAs(User::factory()->superadmin()->create());

    // Enter via ?preview=1: the draft renders, with the floating badge.
    $html = $this->get('http://127.0.0.1/preview-fixture?preview=1')->assertOk()->getContent();
    expect($html)->toContain('ENTWURF-INHALT')
        ->not->toContain('LIVE-INHALT')
        ->toContain('Vorschau: Entwürfe sichtbar');

    // No param: the session keeps the mode active.
    $this->get('http://127.0.0.1/preview-fixture')->assertOk()->assertSee('ENTWURF-INHALT', escape: false);

    // Leave via ?preview=0: back to the applied content, badge gone.
    $html = $this->get('http://127.0.0.1/preview-fixture?preview=0')->assertOk()->getContent();
    expect($html)->toContain('LIVE-INHALT')
        ->not->toContain('ENTWURF-INHALT')
        ->not->toContain('Vorschau: Entwürfe sichtbar');
});

it('activates preview for a member of the tenant', function () {
    $tenant = previewTenant();
    $page = previewPage($tenant);
    stashPreviewDraft($page);

    $member = User::factory()->create();
    $tenant->users()->attach($member, ['role' => TenantUserRole::Editor->value]);

    $this->actingAs($member);

    $this->get('http://127.0.0.1/preview-fixture?preview=1')
        ->assertOk()
        ->assertSee('ENTWURF-INHALT', escape: false);
});

it('ignores the preview param for guests and non-members', function () {
    $tenant = previewTenant();
    $page = previewPage($tenant);
    stashPreviewDraft($page);

    // Guest: live content, no badge.
    $html = $this->get('http://127.0.0.1/preview-fixture?preview=1')->assertOk()->getContent();
    expect($html)->toContain('LIVE-INHALT')->not->toContain('ENTWURF-INHALT');

    // Logged-in, but neither superadmin nor member of this tenant.
    $this->actingAs(User::factory()->create());

    $html = $this->get('http://127.0.0.1/preview-fixture?preview=1')->assertOk()->getContent();
    expect($html)->toContain('LIVE-INHALT')->not->toContain('ENTWURF-INHALT');
});

it('never activates on panel or livewire request paths, even with a sticky session flag', function () {
    $tenant = previewTenant();
    $superadmin = User::factory()->superadmin()->create();

    $session = app('session.store');
    $session->put(PreviewMode::SESSION_KEY.'.'.$tenant->getKey(), true);

    $makeRequest = function (string $url, string $method = 'GET') use ($session, $superadmin) {
        $request = Illuminate\Http\Request::create($url, $method);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $superadmin);

        return $request;
    };

    $previewMode = app(PreviewMode::class);

    // Frontend path: the sticky flag activates the mode …
    $previewMode->activateFromRequest($makeRequest('http://127.0.0.1/irgendwo'), $tenant);
    expect($previewMode->active())->toBeTrue();

    // … but Livewire component endpoints and panel URIs never do — an active
    // overlay there would corrupt admin write flows.
    $previewMode->activateFromRequest($makeRequest('http://127.0.0.1/livewire/update', 'POST'), $tenant);
    expect($previewMode->active())->toBeFalse();

    $previewMode->activateFromRequest($makeRequest('http://127.0.0.1/panel/contents/1/edit'), $tenant);
    expect($previewMode->active())->toBeFalse();
});

it('scopes the sticky preview flag per tenant', function () {
    $tenantA = previewTenant();
    $pageA = previewPage($tenantA);
    stashPreviewDraft($pageA);

    // site_key is unique per install — the second tenant runs on the package
    // default site (its shells carry the badge via the package layout too).
    $tenantB = Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
    ]);
    $pageB = previewPage($tenantB);
    stashPreviewDraft($pageB);

    $this->actingAs(User::factory()->superadmin()->create());

    // Enter preview on tenant A only.
    $this->get('http://127.0.0.1/preview-fixture?preview=1')
        ->assertOk()
        ->assertSee('ENTWURF-INHALT', escape: false);

    // Tenant B shares the session but stays live — no cross-site stickiness.
    $bHtml = $this->get('http://localhost/preview-fixture')->assertOk()->getContent();

    expect($bHtml)->toContain('LIVE-INHALT')
        ->not->toContain('ENTWURF-INHALT')
        ->not->toContain('Vorschau: Entwürfe sichtbar');

    // Tenant A remains sticky.
    $this->get('http://127.0.0.1/preview-fixture')->assertOk()->assertSee('ENTWURF-INHALT', escape: false);
});

// -----------------------------------------------------------------------------
//  Cache coherence
// -----------------------------------------------------------------------------

it('builds guest-facing caches without the overlay during preview requests', function () {
    $tenant = previewTenant();

    // A root onepager section with a pending draft.
    $section = Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.section',
        'title' => 'Live-Sektion',
        'path' => '/',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'blocks' => [[
            'type' => 'section',
            'data' => ['blocks' => [[
                'type' => 'text',
                'data' => ['active' => true, 'content' => '<p>LIVE-SEKTION</p>'],
            ]]],
        ]],
    ]);
    $section->stashDraft(['title' => 'Entwurfs-Sektion']);

    $this->actingAs(User::factory()->superadmin()->create());

    // Sticky preview, then hit the sitemap while its caches are cold: the
    // builder must bypass the overlay (this poisoned sections + sitemap before).
    $this->get('http://127.0.0.1/?preview=1')->assertOk();
    $this->get('http://127.0.0.1/sitemap.xml')->assertOk();

    $packedSections = Cache::get(CacheKeys::sections($tenant->getKey()));

    expect($packedSections)->toBeArray()
        ->and(json_encode($packedSections))->toContain('Live-Sektion')
        ->not->toContain('Entwurfs-Sektion');

    // And the guest really gets the live section from that cache.
    auth()->logout();
    $this->flushSession();

    $this->get('http://127.0.0.1/')->assertOk()
        ->assertSee('Live-Sektion')
        ->assertDontSee('Entwurfs-Sektion');
});

it('keeps the warm path cache on draft stashes and never serves drafts to guests', function () {
    $tenant = previewTenant();
    $page = previewPage($tenant);

    // Guest request warms the forever path cache.
    $this->get('http://127.0.0.1/preview-fixture')->assertOk();
    $cacheKey = CacheKeys::content($tenant->getKey(), '/preview-fixture');
    expect(Cache::has($cacheKey))->toBeTrue();

    // A draft stash leaves the warm cache untouched (observer skips draft-only saves).
    stashPreviewDraft($page->fresh());
    expect(Cache::has($cacheKey))->toBeTrue();

    // A superadmin browses the preview…
    $this->actingAs(User::factory()->superadmin()->create());
    $this->get('http://127.0.0.1/preview-fixture?preview=1')->assertOk()->assertSee('ENTWURF-INHALT', escape: false);

    // …and the guest still gets the applied version afterwards (log out + drop
    // the session so the next request is a real visitor).
    auth()->logout();
    $this->flushSession();
    $this->get('http://127.0.0.1/preview-fixture')->assertOk()->assertSee('LIVE-INHALT', escape: false);

    // Applying content for real DOES invalidate the path cache.
    $page->fresh()->update(['title' => 'Angewendeter Titel']);
    expect(Cache::has($cacheKey))->toBeFalse();
});
