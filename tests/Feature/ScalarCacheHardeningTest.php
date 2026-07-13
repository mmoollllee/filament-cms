<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * Laravel 13 defaults `cache.serializable_classes = false`: cache stores refuse
 * to unserialize PHP objects (gadget-chain hardening for a leaked APP_KEY).
 * Every engine cache payload must therefore be scalar. These tests run the
 * cached lookups through a REAL file store with object unserialization
 * disabled — the array store used elsewhere never serializes and would hide a
 * regression (that is exactly how the original 500 slipped past the suite).
 */
function hardenedCachePath(): string
{
    return storage_path('framework/testing/scalar-cache');
}

beforeEach(function () {
    File::deleteDirectory(hardenedCachePath());
    File::ensureDirectoryExists(hardenedCachePath());

    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => hardenedCachePath(),
        'cache.serializable_classes' => false,
    ]);
});

afterEach(function () {
    File::deleteDirectory(hardenedCachePath());
});

it('resolves the tenant from a file cache that refuses objects', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'hardened.test']);
    $middleware = app(ResolveTenantFromHost::class);

    // First pass queries and warms the cache …
    $middleware->handle(Request::create('http://hardened.test/'), fn () => response('ok'));

    expect(Cache::get(CacheKeys::tenantDomain('hardened.test')))->toBeArray();

    // … the second pass is served from the hardened store and must rehydrate.
    $resolved = null;
    $middleware->handle(Request::create('http://hardened.test/'), function (Request $request) use (&$resolved) {
        $resolved = $request->attributes->get('tenant');

        return response('ok');
    });

    expect($resolved)->toBeInstanceOf(Tenant::class)
        ->and($resolved->getKey())->toBe($tenant->getKey())
        ->and($resolved->primary_domain)->toBe('hardened.test');
});

it('serves content lookups from a file cache that refuses objects', function () {
    $tenant = Tenant::factory()->create();
    $content = Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Gehärtete Seite',
        'path' => '/gehaertete-seite',
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
    ]);

    $resolver = app(ContentResolver::class);

    // Warm, then read back through the hardened store.
    expect($resolver->findByPath($tenant, '/gehaertete-seite')?->getKey())->toBe($content->getKey())
        ->and(Cache::get(CacheKeys::content($tenant->id, '/gehaertete-seite')))->toBeArray();

    $cachedHit = $resolver->findByPath($tenant, '/gehaertete-seite');

    expect($cachedHit)->toBeInstanceOf(Content::class)
        ->and($cachedHit->getKey())->toBe($content->getKey())
        ->and($cachedHit->title)->toBe('Gehärtete Seite')
        // The rehydrated model carries the tenant relation like a fresh lookup.
        ->and($cachedHit->tenant?->getKey())->toBe($tenant->getKey());

    // Negative hits stay plain null payloads (a scalar, too).
    expect($resolver->findByPath($tenant, '/gibt-es-nicht'))->toBeNull()
        ->and($resolver->findByPath($tenant, '/gibt-es-nicht'))->toBeNull();
});

it('serves the onepager section list from a file cache that refuses objects', function () {
    $tenant = Tenant::factory()->create();

    $first = Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.section',
        'title' => 'Start',
        'path' => '/',
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
        'sort' => 10,
    ]);
    $second = Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.section',
        'title' => 'Leistungen',
        'path' => '/leistungen',
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
        'sort' => 20,
    ]);

    $resolver = app(ContentResolver::class);

    // Warm, then read back through the hardened store.
    $warm = $resolver->sections($tenant);
    $cached = $resolver->sections($tenant);

    expect(Cache::get(CacheKeys::sections($tenant->id)))->toBeArray()
        ->and($warm->modelKeys())->toBe([$first->getKey(), $second->getKey()])
        ->and($cached->modelKeys())->toBe([$first->getKey(), $second->getKey()])
        ->and($cached->first())->toBeInstanceOf(Content::class)
        ->and($cached->first()->title)->toBe('Start');
});
