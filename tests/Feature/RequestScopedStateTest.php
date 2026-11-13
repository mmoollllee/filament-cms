<?php

/*
 * Request state lives exactly as long as a request (or a queued job): Octane and the queue
 * worker flush scoped bindings between them, so a long-lived process cannot hand one
 * request's tenant, collision answers or preset classes to the next.
 *
 * And the one package route that needs a tenant resolves it itself: the 404 auto-resolve
 * used to rely on the app having put the tenant middleware on the whole `web` group, and
 * answered every dead URL with no suggestions where it had not.
 */

use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;
use Mmoollllee\Cms\Support\Content\PathConflicts;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Support\TenantCache;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

it('starts every request or job with fresh request state', function (string $service) {
    $first = app($service);

    expect(app($service))->toBe($first);

    // What Octane does before each request and the queue worker before each job.
    app()->forgetScopedInstances();

    expect(app($service))->not->toBe($first);
})->with([
    'the resolved tenant' => CurrentTenant::class,
    'the collision answers' => PathConflicts::class,
    'the preset cache' => LayoutPresetResolver::class,
    'the cache generations' => TenantCache::class,
]);

it('resolves the tenant of its own host on the 404 endpoint', function () {
    $marketing = Tenant::factory()->create(['site_key' => 'marketing', 'primary_domain' => '127.0.0.1']);
    $acme = Tenant::factory()->create(['site_key' => 'acme', 'primary_domain' => 'localhost']);

    foreach ([[$marketing, 'Nur bei Marketing', '/nur-bei-marketing-produktseite'], [$acme, 'Nur bei Acme', '/nur-bei-acme-produktseite']] as [$tenant, $title, $path]) {
        Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'default.page',
            'title' => $title,
            'path' => $path,
            'visibility' => 'public',
            'publish_from' => now()->subDay(),
        ]);
    }

    // Nothing has resolved a tenant yet — the state of every request in a fresh process.
    app(CurrentTenant::class)->forget();

    $own = $this->getJson('http://127.0.0.1/_resolve404?path=/nur-bei-marketing-produktseit')->json();

    // Another host after it: the answer follows the host, not whoever was resolved before.
    $other = $this->getJson('http://localhost/_resolve404?path=/nur-bei-marketing-produktseit')->json();

    expect(json_encode($own))->toContain('/nur-bei-marketing-produktseite')
        ->and(json_encode($other))->not->toContain('/nur-bei-marketing-produktseite');
});
