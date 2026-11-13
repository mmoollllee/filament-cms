<?php

/*
 * Every frontend cache of a tenant lives under one generation ({@see TenantCache}), and a
 * write moves that generation on only once it has committed:
 *
 * - never inside the transaction, where a visitor arriving between the invalidation and
 *   the commit used to put the old row straight back into the cache;
 * - never for a write that rolls back;
 * - and a read that raced a write is filed under the old generation, where nobody reads it.
 *
 * Because the generation covers everything, a write also reaches caches nobody named:
 * a menu linking a page that was just renamed.
 */

use Illuminate\Support\Facades\DB;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Support\TenantCache;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['site_key' => 'marketing']);
    app(CurrentTenant::class)->set($this->tenant);
    $this->cache = app(TenantCache::class);
});

function cachedPage(Tenant $tenant, string $title, string $path): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => $title,
        'path' => $path,
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
    ]);
}

it('moves the generation on only once the write has committed', function () {
    $before = $this->cache->generation($this->tenant->id);

    DB::transaction(function () use ($before): void {
        cachedPage($this->tenant, 'Neu', '/neu');

        // Still inside the transaction: a visitor now would cache what is committed.
        expect($this->cache->generation($this->tenant->id))->toBe($before);
    });

    expect($this->cache->generation($this->tenant->id))->not->toBe($before);
});

it('keeps the generation for a write that rolls back', function () {
    $before = $this->cache->generation($this->tenant->id);

    try {
        DB::transaction(function (): void {
            cachedPage($this->tenant, 'Verworfen', '/verworfen');

            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
        // The write is gone, and so must be its effect on the cache.
    }

    expect($this->cache->generation($this->tenant->id))->toBe($before);
});

it('keeps the generation for a redirect saved without a change', function () {
    $redirect = Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/alt',
        'to_url' => 'https://example.test/neu',
        'status_code' => 301,
        'origin' => RedirectOrigin::Manual,
    ]);

    $before = $this->cache->generation($this->tenant->id);

    // The 404 resolver re-saves an unchanged suggestion on every call: plain 404 traffic
    // must not empty the tenant's cache.
    $redirect->fresh()->save();

    expect($this->cache->generation($this->tenant->id))->toBe($before);

    $redirect->fresh()->update(['to_url' => 'https://example.test/neuer']);

    expect($this->cache->generation($this->tenant->id))->not->toBe($before);
});

it('files a read that raced a write under the old generation', function () {
    // The reader takes the generation, then reads the database…
    $generation = $this->cache->generation($this->tenant->id);

    // …a write commits in the meantime…
    $this->cache->flush($this->tenant->id);

    // …and the reader stores what it read before the commit.
    $this->cache->put($this->tenant->id, 'tenant:probe', 'alter Stand', null, $generation);

    expect($this->cache->get($this->tenant->id, 'tenant:probe', 'verfehlt'))->toBe('verfehlt');
});

it('serves a page from the cache until its tenant writes', function () {
    $page = cachedPage($this->tenant, 'Stabil', '/stabil');
    $resolver = app(ContentResolver::class);

    $resolver->findByPath($this->tenant, '/stabil');

    DB::enableQueryLog();
    $resolver->findByPath($this->tenant, '/stabil');
    $queriesOnHit = count(DB::getQueryLog());

    $page->update(['title' => 'Geändert']);

    expect($queriesOnHit)->toBe(0)
        ->and($resolver->findByPath($this->tenant, '/stabil')?->title)->toBe('Geändert');
});

it('shows a renamed page under its new address in a menu that links it', function () {
    $page = cachedPage($this->tenant, 'Angebot', '/angebot');

    makeHeaderMenu($this->tenant, [
        'title' => 'Angebot',
        'url' => null,
        'linkable_type' => $page->getMorphClass(),
        'linkable_id' => $page->getKey(),
    ]);

    expect(collect(Menu::linksForLocation('header', $this->tenant))->pluck('path')->all())->toContain('/angebot');

    $page->update(['path' => '/aktion']);

    expect(collect(Menu::linksForLocation('header', $this->tenant))->pluck('path')->all())
        ->toContain('/aktion')
        ->not->toContain('/angebot');
});

it('keeps its wrapped entries apart from the bare ones earlier releases wrote', function () {
    // Code of an earlier release — a rollback, an old worker mid-deploy — reads these
    // spellings and would take a {generation, value} wrapper for its payload.
    expect([
        CacheKeys::content(1, '/seite'),
        CacheKeys::sections(1),
        CacheKeys::sitemap(1),
        CacheKeys::candidates(1),
        CacheKeys::redirects(1),
        CacheKeys::menu(1, 'header'),
    ])->not->toContain(
        'tenant:1:content:v2:/seite',
        'tenant:1:sections:v2',
        'tenant:1:sitemap',
        'tenant:1:candidates',
        'tenant:1:redirects',
        'tenant:1:menu:header',
    );
});
