<?php

use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\TenantCache;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * ContentResolver::findByPath() caches per (tenant, normalized path) under the tenant's
 * cache generation. Renaming a content's path must retire the OLD entry too — otherwise the
 * old URL keeps serving stale content instead of falling through to the redirect/404
 * pipeline. The testbench array cache persists within a single test, so a stale read here
 * means the invalidation is missing.
 */
it('drops the old path cache when a content path is renamed', function () {
    $tenant = Tenant::factory()->create();
    $resolver = app(ContentResolver::class);

    $content = Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Alte Seite',
        'path' => '/alte-seite',
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
    ]);

    $oldPath = $content->path;
    $oldKey = "tenant:{$tenant->id}:content:v2:{$oldPath}";
    $cache = app(TenantCache::class);

    // Warm the anonymous-visitor cache for the old path.
    expect($resolver->findByPath($tenant, $oldPath)?->getKey())->toBe($content->getKey())
        ->and($cache->get($tenant->id, $oldKey, false))->toBeArray();

    // Rename the path → the observer moves the tenant's cache generation on, so the old
    // entry stops counting, whichever key it sits under.
    $content->update(['path' => '/neue-seite']);

    expect($content->fresh()->path)->toBe('/neue-seite')
        ->and($cache->get($tenant->id, $oldKey, false))->toBeFalse()
        // The old path no longer resolves to the content (would 404 → redirect pipeline).
        ->and($resolver->findByPath($tenant, $oldPath))->toBeNull();
});
