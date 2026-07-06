<?php

use Workbench\App\Models\Tenant;

it('treats the first tenant as the branding source', function () {
    $source = Tenant::factory()->create(['brand_claim' => 'Claim A']);
    $sub = Tenant::factory()->create(['brand_claim' => null]);

    expect($source->isBrandingSource())->toBeTrue()
        ->and($sub->isBrandingSource())->toBeFalse();
});

it('inherits branding from the source when own values are empty', function () {
    $source = Tenant::factory()->create([
        'name' => 'Source GmbH',
        'brand_name' => 'Brand A',
        'brand_claim' => 'Claim A',
    ]);

    $sub = Tenant::factory()->create([
        'brand_name' => null,
        'brand_claim' => null,
    ]);

    expect($sub->resolvedBrandClaim())->toBe('Claim A')
        ->and($sub->displayName())->toBe('Brand A');
});

it('keeps own branding when set', function () {
    Tenant::factory()->create(['brand_claim' => 'Claim A']);
    $sub = Tenant::factory()->create(['brand_claim' => 'Own Claim']);

    expect($sub->resolvedBrandClaim())->toBe('Own Claim');
});
