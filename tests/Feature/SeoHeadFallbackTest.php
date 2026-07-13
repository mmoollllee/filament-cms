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

it('renders nothing without a resolvable tenant', function () {
    // CurrentTenant deliberately left empty — e.g. an error page on an
    // unresolved domain. Must stay silent instead of fataling on null.
    $rendered = Blade::render('<x-site.seo-head />');

    expect(trim($rendered))->toBe('');
});

it('keeps json-ld inert when branding values contain a script breakout', function () {
    // JSON_UNESCAPED_SLASHES alone leaves '</script>' intact inside JSON strings;
    // JSON_HEX_TAG must escape <> so the script tag cannot be closed early.
    $payload = 'Evil</script><svg onload=alert(1)>';

    $tenant = Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
        'name' => $payload,
        'brand_name' => $payload,
    ]);

    app(CurrentTenant::class)->set($tenant);

    $rendered = Blade::render('<x-site.seo-head />');

    // The raw breakout must never appear; the hex-escaped JSON encoding must.
    $escapedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    expect($rendered)
        ->not->toContain('</script><svg')
        ->toContain($escapedPayload);
});
