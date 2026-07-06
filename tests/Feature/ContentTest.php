<?php

use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

it('scopes content rows to their tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    Content::factory()->count(2)->create(['tenant_id' => $a->id]);
    Content::factory()->create(['tenant_id' => $b->id]);

    expect(Content::query()->where('tenant_id', $a->id)->count())->toBe(2)
        ->and(Content::query()->where('tenant_id', $b->id)->count())->toBe(1);
});

it('auto-generates a URL path from the title on save', function () {
    $tenant = Tenant::factory()->create();

    $content = Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Über Uns',
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
    ]);

    expect($content->resolvedPath())->not->toBeNull();
});
