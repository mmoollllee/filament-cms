<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Tenant;

uses(RefreshDatabase::class);


it('passes an empty honeypot and trips on a filled one', function () {
    $host = tenantFormHost();

    expect($host->tripped())->toBeFalse()
        ->and($host->submitted)->toBeFalse();

    $host->website = 'http://spam.example';

    expect($host->tripped())->toBeTrue()
        ->and($host->submitted)->toBeTrue();
});

it('builds a tenant- and ip-scoped rate-limit key', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    expect(tenantFormHost()->key('kontakt'))
        ->toStartWith('kontakt:'.$tenant->getKey().':');
});

it('resolves the recipient from an override, else the tenant contact email', function () {
    $tenant = Tenant::factory()->create(['contact_email' => 'team@example.test']);
    app(CurrentTenant::class)->set($tenant);

    expect(tenantFormHost()->recipient('override@example.test'))->toBe('override@example.test')
        ->and(tenantFormHost()->recipient(null))->toBe('team@example.test');
});
