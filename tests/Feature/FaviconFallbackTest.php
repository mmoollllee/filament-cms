<?php

use Illuminate\Support\Facades\Blade;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Tenant;

/**
 * Pins the brand-agnostic <x-site.favicon> fallback: it resolves the tenant
 * itself (no caller locals or app-side view composer required), emits a single
 * icon link from favicon_path (branding inheritance included), and stays
 * silent when no favicon is configured — apps with full icon sets override
 * the component.
 */
it('renders an icon link from the tenant favicon path', function () {
    $tenant = Tenant::factory()->create(['favicon_path' => 'branding/favicon.svg']);
    app(CurrentTenant::class)->set($tenant);

    $rendered = Blade::render('<x-site.favicon />');

    expect($rendered)
        ->toContain('rel="icon"')
        ->toContain('/storage/branding/favicon.svg');
});

it('inherits the branding tenant favicon when the tenant has none', function () {
    Tenant::factory()->create(['favicon_path' => 'branding/brand-favicon.png']);
    $sub = Tenant::factory()->create(['favicon_path' => null]);
    app(CurrentTenant::class)->set($sub);

    $rendered = Blade::render('<x-site.favicon />');

    expect($rendered)->toContain('/storage/branding/brand-favicon.png');
});

it('renders nothing when no favicon is configured anywhere', function () {
    $tenant = Tenant::factory()->create(['favicon_path' => null]);
    app(CurrentTenant::class)->set($tenant);

    $rendered = Blade::render('<x-site.favicon />');

    expect(trim($rendered))->toBe('');
});

it('renders nothing without a resolvable tenant', function () {
    // CurrentTenant deliberately left empty — e.g. an error page on an
    // unresolved domain. Must stay silent instead of fataling on null.
    $rendered = Blade::render('<x-site.favicon />');

    expect(trim($rendered))->toBe('');
});
