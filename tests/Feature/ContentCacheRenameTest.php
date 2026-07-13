<?php

use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * ContentResolver::findByPath() caches per (tenant, normalized path) via rememberForever.
 * Renaming a content's path must forget the OLD key too — otherwise the old URL keeps
 * serving stale content forever instead of falling through to the redirect/404 pipeline.
 * The testbench array cache persists within a single test, so a stale read here means the
 * old-path invalidation is missing.
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

    // Warm the anonymous-visitor cache for the old path.
    expect($resolver->findByPath($tenant, $oldPath)?->getKey())->toBe($content->getKey())
        ->and(Cache::has($oldKey))->toBeTrue();

    // Rename the path → the observer must forget the old key.
    $content->update(['path' => '/neue-seite']);

    expect($content->fresh()->path)->toBe('/neue-seite')
        ->and(Cache::has($oldKey))->toBeFalse()
        // The old path no longer resolves to the content (would 404 → redirect pipeline).
        ->and($resolver->findByPath($tenant, $oldPath))->toBeNull();
});
