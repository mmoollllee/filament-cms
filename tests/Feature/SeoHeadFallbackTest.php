<?php

use Illuminate\Support\Facades\Blade;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Tenant;

/**
 * Pins the brand-agnostic <x-site.seo-head> fallback: it resolves the tenant
 * itself (no caller locals or app-side view composer required) and emits
 * JSON-LD with intact '@context' keys. Regression guard: a literal '@context'
 * in template text is compiled as Blade's @context directive (Laravel 12) and
 * leaks PHP code ($__contextArgs …) into the markup — the schema arrays must
 * be built inside the @php block.
 */
function seoHeadFallbackTenant(): Tenant
{
    $tenant = Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
    ]);

    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

it('renders organization json-ld with an intact @context key', function () {
    $tenant = seoHeadFallbackTenant();

    $rendered = Blade::render('<x-site.seo-head />');

    expect($rendered)
        ->toContain('"@context":"https://schema.org"')
        ->toContain('"@type":"Organization"')
        ->toContain($tenant->displayName())
        ->not->toContain('__contextArgs');
});

it('renders breadcrumb json-ld with an intact @context key', function () {
    seoHeadFallbackTenant();

    $rendered = Blade::render(
        '<x-site.seo-head :breadcrumbs="$breadcrumbs" />',
        ['breadcrumbs' => [
            ['label' => 'Start', 'path' => '/'],
            ['label' => 'Kontakt', 'path' => '/kontakt'],
        ]],
    );

    expect($rendered)
        ->toContain('"@type":"BreadcrumbList"')
        ->toContain('"name":"Kontakt"')
        ->not->toContain('__contextArgs')
        ->and(substr_count($rendered, '"@context":"https://schema.org"'))->toBe(2);
});

it('omits breadcrumb json-ld when no breadcrumbs are given', function () {
    seoHeadFallbackTenant();

    $rendered = Blade::render('<x-site.seo-head />');

    expect($rendered)->not->toContain('BreadcrumbList');
});

it('resolves the tenant from the request-scoped singleton without a tenant prop', function () {
    $tenant = seoHeadFallbackTenant();

    $rendered = Blade::render('<x-site.seo-head />');

    expect($rendered)
        ->toContain('<link rel="canonical"')
        ->toContain('og:title')
        ->toContain($tenant->frontendTitleFor(null));
});
