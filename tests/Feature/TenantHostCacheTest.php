<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Models\Tenant;

/**
 * ResolveTenantFromHost keys a `rememberForever`-style cache on the (spoofable) Host header.
 * It must cache only real hits — never the miss — so an attacker sending junk Host headers
 * can't grow the cache store without bound, and a domain assigned to a tenant AFTER a first
 * miss still resolves without a manual cache clear.
 */
it('caches a resolved tenant host as a hit', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'known-host.test']);
    $middleware = app(ResolveTenantFromHost::class);

    $middleware->handle(Request::create('http://known-host.test/'), fn () => response('ok'));

    expect(Cache::get('tenant_domain:known-host.test')?->getKey())->toBe($tenant->getKey());
});

it('does not cache a miss for an unknown host', function () {
    $middleware = app(ResolveTenantFromHost::class);

    expect(fn () => $middleware->handle(Request::create('http://unknown-host.test/'), fn () => response('ok')))
        ->toThrow(NotFoundHttpException::class);

    // The miss must NOT be persisted (else junk Host headers grow the store unbounded).
    expect(Cache::has('tenant_domain:unknown-host.test'))->toBeFalse();
});

it('resolves a host assigned after an earlier miss without a manual cache clear', function () {
    $middleware = app(ResolveTenantFromHost::class);

    // First request misses (no tenant yet) and must not poison the cache with a null.
    expect(fn () => $middleware->handle(Request::create('http://late-host.test/'), fn () => response('ok')))
        ->toThrow(NotFoundHttpException::class);

    Tenant::factory()->create(['primary_domain' => 'late-host.test']);

    // The now-registered domain resolves on the next request.
    $response = $middleware->handle(Request::create('http://late-host.test/'), fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});
