<?php

use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

it('seeds the two-tenant demo', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Tenant::query()->count())->toBe(2)
        ->and(User::query()->where('is_superadmin', true)->count())->toBe(1);

    $source = Tenant::query()->where('site_key', 'marketing')->first();
    $sub = Tenant::query()->where('site_key', 'acme')->first();

    expect($source)->not->toBeNull()
        ->and($sub)->not->toBeNull()
        ->and($source->isBrandingSource())->toBeTrue()   // first tenant (id 1)
        ->and($sub->isBrandingSource())->toBeFalse()
        ->and($source->resolvedBrandName())->toBe('filament-cms')
        ->and($sub->resolvedBrandName())->toBe('filament-cms') // inherited from A
        ->and($sub->resolvedBrandClaim())->toBe('The multi-tenant CMS toolkit for Filament'); // inherited from A
});

it('seeds site-extension content for the branding tenant', function () {
    $this->seed(DatabaseSeeder::class);

    $source = Tenant::query()->where('site_key', 'marketing')->first();

    expect(
        Content::query()
            ->where('tenant_id', $source->id)
            ->where('content_type', 'marketing.service')
            ->exists()
    )->toBeTrue();
});

it('links the howto guides as children of the hub page', function () {
    $this->seed(DatabaseSeeder::class);

    $hub = Content::query()->where('path', '/howto')->firstOrFail();

    expect(Content::query()->where('parent_id', $hub->id)->pluck('path')->sort()->values()->all())
        ->toBe(['/howto/custom-blocks', '/howto/tiptap-extensions']);
});
