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
use Mmoollllee\Cms\Models\Menu;
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
