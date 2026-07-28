<?php

/*
 * Time-driven (un)publishing vs. the write-invalidated frontend caches:
 *
 * The content status derives from the publishing window, but every guest-facing
 * cache used to be `rememberForever` + observer-invalidation — an expired page
 * kept serving and a scheduled one stayed hidden until the NEXT WRITE. The
 * caches now store with a TTL ending at the tenant's next publishing transition
 * ({@see \Mmoollllee\Cms\Support\Content\PublishingTransitions}); the path
 * cache uses the page's own publish_until. These tests time-travel WITHOUT any
 * intervening write — under the old behavior every one of them fails.
 */

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Content\PublishingTransitions;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * Every test here builds publishing windows from now() and then asserts against
 * now()-derived expectations, so the wall clock must not advance mid-test: a
 * fixture created at …:07.999 and an expectation computed at …:08.001 differ by
 * a whole second once the timestamps are truncated. That made
 * "the earliest future window boundary" fail roughly one full suite run in four.
 *
 * travel() keeps working — it moves the frozen instant forward and leaves it
 * frozen — which is exactly the semantics these tests want.
 */
beforeEach(function () {
    $this->freezeTime();
});

function transitionTenant(): Tenant
{
    return Tenant::factory()->create([
        'primary_domain' => '127.0.0.1',
        'site_key' => 'marketing',
    ]);
}

function transitionPage(Tenant $tenant, string $path, ?CarbonInterface $from, ?CarbonInterface $until = null): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Fixture '.$path,
        'path' => $path,
        'visibility' => ContentVisibility::Public,
        'publish_from' => $from,
        'publish_until' => $until,
        'blocks' => [[
            'type' => 'section',
            'data' => ['blocks' => [[
                'type' => 'text',
                'data' => ['active' => true, 'content' => '<p>INHALT '.$path.'</p>'],
            ]]],
        ]],
    ]);
}

function transitionSection(Tenant $tenant, string $path, ?CarbonInterface $from, ?CarbonInterface $until = null): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.section',
        'title' => 'Sektion '.$path,
        'path' => $path,
        'visibility' => ContentVisibility::Public,
        'publish_from' => $from,
        'publish_until' => $until,
    ]);
}

it('stops serving a page once its publish_until passes — without any write', function () {
    $tenant = transitionTenant();
    transitionPage($tenant, '/befristet', from: now()->subDay(), until: now()->addHour());

    // Guest request warms the path cache — but only until publish_until.
    $this->get('http://127.0.0.1/befristet')->assertOk();
    expect(Cache::has(CacheKeys::content($tenant->getKey(), '/befristet')))->toBeTrue();

    $this->travel(2)->hours();

    // No write happened; the cache entry must have died with the window.
    $this->get('http://127.0.0.1/befristet')->assertNotFound();
});

it('surfaces a scheduled page at publish_from — the 404-miss cache is short-lived', function () {
    $tenant = transitionTenant();
    transitionPage($tenant, '/kommt-noch', from: now()->addHour());

    $this->get('http://127.0.0.1/kommt-noch')->assertNotFound();

    $this->travel(2)->hours();

    $this->get('http://127.0.0.1/kommt-noch')->assertOk()
        ->assertSee('INHALT /kommt-noch', escape: false);
});

it('lets a scheduled section join (and an expiring one leave) the cached sections on time', function () {
    $tenant = transitionTenant();
    // default.section: the onepager-participating type (default.page is standalone).
    $expiring = transitionSection($tenant, '/sektion-laeuft-ab', from: now()->subDay(), until: now()->addHour());
    $scheduled = transitionSection($tenant, '/sektion-kommt-noch', from: now()->addHours(2));

    $resolver = app(ContentResolver::class);

    $before = $resolver->sections($tenant)->pluck('id')->all();

    expect($before)->toContain($expiring->id)
        ->not->toContain($scheduled->id);

    // Past BOTH transitions, still without a single write.
    $this->travel(3)->hours();

    $after = $resolver->sections($tenant)->pluck('id')->all();

    expect($after)->toContain($scheduled->id)
        ->not->toContain($expiring->id);
});

it('computes the next transition as the earliest future window boundary', function () {
    $tenant = transitionTenant();
    transitionPage($tenant, '/a', from: now()->subDay(), until: now()->addHours(5));
    transitionPage($tenant, '/b', from: now()->addHours(2));
    transitionPage($tenant, '/c', from: now()->subDay());

    expect(PublishingTransitions::nextFor($tenant)?->getTimestamp())
        ->toBe(now()->addHours(2)->getTimestamp());

    // No pending boundary → null (cache forever, writes invalidate as before).
    $this->travel(6)->hours();

    expect(PublishingTransitions::nextFor($tenant))->toBeNull();
});
