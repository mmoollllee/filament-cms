<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mmoollllee\Cms\Cms;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;

uses(RefreshDatabase::class);

function textFragmentBlocks(string $copy): array
{
    return [[
        'type' => 'text',
        'data' => ['active' => true, 'content' => "<p>{$copy}</p>", 'heading' => 'h2'],
    ]];
}

it('resolves the fragment model class from config', function () {
    expect(Cms::fragmentModel())->toBe(Fragment::class);
});

it('reports content based on the blocks array', function () {
    $tenant = Tenant::factory()->create();

    $withBlocks = Fragment::create([
        'tenant_id' => $tenant->id, 'title' => 'A', 'slug' => 'a', 'blocks' => textFragmentBlocks('Hi'),
    ]);
    $empty = Fragment::create([
        'tenant_id' => $tenant->id, 'title' => 'B', 'slug' => 'b', 'blocks' => [],
    ]);

    expect($withBlocks->hasContent())->toBeTrue()
        ->and($empty->hasContent())->toBeFalse();
});

it('resolves by slug, preferring the own tenant over the branding tenant', function () {
    $branding = Tenant::factory()->create();   // lowest id → branding source
    Fragment::create(['tenant_id' => $branding->id, 'title' => 'Shared', 'slug' => 'cta', 'blocks' => textFragmentBlocks('From branding')]);

    $own = Tenant::factory()->create();
    Fragment::create(['tenant_id' => $own->id, 'title' => 'Own', 'slug' => 'cta', 'blocks' => textFragmentBlocks('Own override')]);

    expect(Fragment::resolveFragment($own, 'cta')?->blocks[0]['data']['content'])->toContain('Own override');
});

it('inherits a fragment from the branding tenant when the own tenant has none', function () {
    $branding = Tenant::factory()->create();
    Fragment::create(['tenant_id' => $branding->id, 'title' => 'Shared', 'slug' => 'cta', 'blocks' => textFragmentBlocks('Inherited copy')]);

    $other = Tenant::factory()->create();

    expect(Fragment::resolveFragment($other, 'cta')?->blocks[0]['data']['content'])->toContain('Inherited copy')
        ->and(Fragment::resolveFragment($other, 'missing'))->toBeNull();
});

it('busts the per-tenant cache on save', function () {
    $tenant = Tenant::factory()->create();

    expect(Fragment::resolveFragment($tenant, 'cta'))->toBeNull();

    Fragment::create(['tenant_id' => $tenant->id, 'title' => 'CTA', 'slug' => 'cta', 'blocks' => textFragmentBlocks('Now here')]);

    expect(Fragment::resolveFragment($tenant, 'cta'))->not->toBeNull();
});
