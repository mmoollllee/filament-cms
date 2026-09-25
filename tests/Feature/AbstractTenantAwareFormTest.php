<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Livewire\AnalyticsTestForm;
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

it('stamps a submission with the local time of the site', function () {
    Carbon::setTestNow('2026-09-25 07:30:00');

    // The server runs on UTC; without a site there is no other time to name.
    expect(tenantFormHost()->stamp())->toBe('25.09.2026 07:30');

    app(CurrentTenant::class)->set(Tenant::factory()->create(['timezone' => 'Europe/Berlin']));

    expect(tenantFormHost()->stamp())->toBe('25.09.2026 09:30');
});

it('remembers the page the form renders on, an explicit url winning', function () {
    expect(tenantFormHost()->captureUrl(null))->toBe(request()->url())
        ->and(tenantFormHost()->captureUrl('https://example.test/kontakt'))->toBe('https://example.test/kontakt');
});

it('locks the source url against the browser', function () {
    Livewire::test(AnalyticsTestForm::class)
        ->set('sourceUrl', 'https://evil.example');
})->throws(CannotUpdateLockedPropertyException::class);
