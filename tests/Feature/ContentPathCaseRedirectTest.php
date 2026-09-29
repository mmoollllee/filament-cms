<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Http\Middleware\CanonicalizeTrailingSlash;
use Mmoollllee\Cms\Support\CacheKeys;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * Paths are lower-case slugs. A request in another letter case must not become a second
 * address for the same page: MySQL's case-insensitive collation would otherwise serve
 * "/Leistungen" with a self-referencing canonical. It is folded into the real path with a 301.
 */

beforeEach(function () {
    $tenant = Tenant::factory()->create([
        'primary_domain' => '127.0.0.1',
        'site_key' => 'marketing',
    ]);

    Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Leistungen',
        'path' => '/leistungen',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'blocks' => [],
    ]);
});

it('redirects a path in another letter case to the page permanently', function () {
    $this->get('http://127.0.0.1/Leistungen')->assertRedirect('/leistungen')->assertStatus(301);
    $this->get('http://127.0.0.1/LEISTUNGEN')->assertRedirect('/leistungen')->assertStatus(301);
});

it('keeps the query string across the redirect', function () {
    $this->get('http://127.0.0.1/Leistungen?utm_source=flyer')
        ->assertRedirect('/leistungen?utm_source=flyer');
});

it('serves the page itself without a redirect', function () {
    $this->get('http://127.0.0.1/leistungen')->assertOk();
});

it('still answers 404 when no page matches in any letter case', function () {
    $this->get('http://127.0.0.1/Gibt-Es-Nicht')->assertNotFound();
});

it('scans the contents only once for a mixed-case path that matches nothing', function () {
    DB::enableQueryLog();

    $this->get('http://127.0.0.1/Gibt-Es-Nicht')->assertNotFound();

    // The full-scan fallback is the one content query without a WHERE on the path.
    $scans = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, 'from "contents"') && ! str_contains($query, '"path"'));

    expect($scans)->toHaveCount(1);
});

it('answers a repeated dead URL from the cache instead of scanning again', function () {
    $this->get('http://127.0.0.1/gibt-es-nicht')->assertNotFound();

    DB::enableQueryLog();
    $this->get('http://127.0.0.1/gibt-es-nicht')->assertNotFound();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'from "contents"')))
        ->toBeEmpty();
});

it('does not cache a page under a spelling the redirect folds away', function () {
    $this->get('http://127.0.0.1/Leistungen')->assertRedirect('/leistungen');

    // The cache observer busts the keys of a page's own path only; a variant key would
    // outlive a rename or delete of the page.
    $tenantId = Tenant::query()->value('id');

    expect(Cache::get(CacheKeys::content($tenantId, '/Leistungen'), false))->toBeFalse();
});

describe('behind CanonicalizeTrailingSlash', function () {
    beforeEach(function () {
        $this->app->make(Kernel::class)->prependMiddleware(CanonicalizeTrailingSlash::class);
    });

    // A query string keeps the trailing slash through the test client, which otherwise
    // normalises it away before the middleware sees it.

    it('reaches the page in one redirect when case and trailing slash are both off', function () {
        $this->get('http://127.0.0.1/Leistungen/?ref=x')
            ->assertStatus(301)
            ->assertRedirect('http://127.0.0.1/leistungen?ref=x');
    });

    it('still strips the trailing slash of a lower-case path itself', function () {
        $this->get('http://127.0.0.1/leistungen/?ref=x')
            ->assertStatus(301)
            ->assertRedirect('http://127.0.0.1/leistungen?ref=x');
    });

    it('strips the trailing slash of a page whose own path has upper case', function () {
        Content::create([
            'tenant_id' => Tenant::query()->value('id'),
            'content_type' => 'default.page',
            'title' => 'FAQ',
            'path' => '/FAQ',
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subDay(),
            'blocks' => [],
        ]);

        $this->get('http://127.0.0.1/FAQ/?ref=x')
            ->assertStatus(301)
            ->assertRedirect('http://127.0.0.1/FAQ?ref=x');
        $this->get('http://127.0.0.1/FAQ')->assertOk();
    });

    it('does not mistake the hex of a percent escape for upper case', function () {
        $this->get('http://127.0.0.1/stra%C3%9Fenbau/?ref=x')
            ->assertStatus(301)
            ->assertRedirect('http://127.0.0.1/stra%C3%9Fenbau?ref=x');
    });
});
