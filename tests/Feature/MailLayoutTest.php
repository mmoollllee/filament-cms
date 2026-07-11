<?php

use Illuminate\Support\Facades\Blade;
use Workbench\App\Models\Tenant;

function renderMail(Tenant $tenant, array $attributes = [], string $body = 'Body copy.'): string
{
    $attrs = collect(array_merge(['heading' => 'Neue Nachricht'], $attributes))
        ->map(fn ($value, $key) => $key.'="'.$value.'"')
        ->implode(' ');

    return Blade::render(
        '<x-cms::mail :tenant="$tenant" '.$attrs.'>'.$body.'</x-cms::mail>',
        ['tenant' => $tenant],
    );
}

it('wraps body content with the heading and slot', function () {
    $tenant = Tenant::factory()->create(['brand_name' => 'Acme']);

    $html = renderMail($tenant, ['heading' => 'Willkommen'], 'Hallo Welt');

    expect($html)
        ->toContain('<h1')
        ->toContain('Willkommen')
        ->toContain('Hallo Welt');
});

it('uses the tenant primary color for brand accents', function () {
    $tenant = Tenant::factory()->create(['primary_color' => '#123abc']);

    expect(renderMail($tenant))->toContain('#123abc');
});

it('falls back to the engine default color when the tenant has none', function () {
    $tenant = Tenant::factory()->create(['primary_color' => null]);

    expect(renderMail($tenant))->toContain('#005f4e');
});

it('renders the tenant logo when one is configured', function () {
    $tenant = Tenant::factory()->create([
        'brand_name' => 'Acme',
        'logo_path' => 'logos/acme-logo.png',
    ]);

    expect(renderMail($tenant))
        ->toContain('<img')
        ->toContain('logos/acme-logo.png')
        ->toContain('alt="Acme"');
});

it('falls back to the brand name when no logo is configured', function () {
    $tenant = Tenant::factory()->create([
        'brand_name' => 'Acme',
        'logo_path' => null,
    ]);

    $html = renderMail($tenant);

    expect($html)
        ->not->toContain('<img')
        ->toContain('Acme');
});

it('renders footer identity and contact from tenant site settings', function () {
    $tenant = Tenant::factory()->create([
        'brand_name' => 'Acme',
        'company_name' => 'Acme Bau GmbH',
        'street' => 'Musterweg 1',
        'postal_code' => '89073',
        'city' => 'Ulm',
        'contact_email' => 'info@acme.test',
    ]);

    expect(renderMail($tenant))
        ->toContain('Acme Bau GmbH')
        ->toContain('Musterweg 1')
        ->toContain('89073 Ulm')
        ->toContain('mailto:info@acme.test');
});

it('emits hidden preheader text when provided', function () {
    $tenant = Tenant::factory()->create();

    expect(renderMail($tenant, ['preheader' => 'Kurzvorschau im Postfach']))
        ->toContain('Kurzvorschau im Postfach');
});
