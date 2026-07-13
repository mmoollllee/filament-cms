<?php

use Illuminate\Support\Facades\Blade;
use Mmoollllee\Cms\Support\Mail\MailLogo;
use Workbench\App\Models\Tenant;

it('passes a raster logo through as an absolute URL', function () {
    $tenant = Tenant::factory()->create(['logo_path' => 'logos/acme.png']);

    $url = MailLogo::urlFor($tenant);

    expect($url)
        ->toContain('logos/acme.png')
        ->and(str_starts_with($url, 'http'))->toBeTrue();
});

it('returns null for a tenant without a logo', function () {
    $tenant = Tenant::factory()->create(['logo_path' => null]);

    expect(MailLogo::urlFor($tenant))->toBeNull();
});

it('prefers the dedicated mail logo over the main logo', function () {
    $tenant = Tenant::factory()->create([
        'logo_path' => 'logos/acme.svg',            // main is SVG (unusable in mail)
        'mail_logo_path' => 'logos/acme-mail.png',  // dedicated raster wins
    ]);

    expect(MailLogo::urlFor($tenant))->toContain('logos/acme-mail.png');
});

it('inherits the dedicated mail logo from the branding tenant', function () {
    // The branding source is the first tenant created (lowest id).
    Tenant::factory()->create(['mail_logo_path' => 'logos/brand-mail.png']);
    $sub = Tenant::factory()->create(['mail_logo_path' => null, 'logo_path' => null]);

    expect(MailLogo::urlFor($sub))->toContain('logos/brand-mail.png');
});

it('hosts the logo URL on the sending tenant domain, not the branding tenant domain', function () {
    // The FILE is branding-inherited, but the URL must reference the domain the
    // mail is sent from — a jobs subsite must not link back to the main site.
    Tenant::factory()->create([
        'primary_domain' => 'main.example.test',
        'mail_logo_path' => 'logos/brand-mail.png',
    ]);
    $sub = Tenant::factory()->create([
        'primary_domain' => 'jobs.example.test',
        'mail_logo_path' => null,
        'logo_path' => null,
    ]);

    $url = MailLogo::urlFor($sub);

    expect($url)
        ->toContain('://jobs.example.test/')
        ->toContain('logos/brand-mail.png')
        ->not->toContain('main.example.test');
});

it('falls back to app.url when the sending tenant has no primary domain', function () {
    // Schema-wise the column is NOT NULL; a blank value is the closest real-world
    // shape of "no domain configured" and exercises the same fallback branch.
    $tenant = Tenant::factory()->create([
        'primary_domain' => '',
        'logo_path' => 'logos/acme.png',
    ]);

    $url = MailLogo::urlFor($tenant);

    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    expect($url)
        ->toContain('logos/acme.png')
        ->toContain('://'.$appHost);
});

it('lets a tenant override the inherited mail logo', function () {
    Tenant::factory()->create(['mail_logo_path' => 'logos/brand-mail.png']);
    $sub = Tenant::factory()->create(['mail_logo_path' => 'logos/sub-mail.png']);

    expect(MailLogo::urlFor($sub))->toContain('logos/sub-mail.png');
});

it('inherits the branding main logo when nothing else is set', function () {
    Tenant::factory()->create(['mail_logo_path' => null, 'logo_path' => 'logos/brand-main.png']);
    $sub = Tenant::factory()->create(['mail_logo_path' => null, 'logo_path' => null]);

    expect(MailLogo::urlFor($sub))->toContain('logos/brand-main.png');
});

it('returns null for an SVG logo (not shown in mail clients)', function () {
    $tenant = Tenant::factory()->create(['logo_path' => 'logos/acme.svg']);

    expect(MailLogo::urlFor($tenant))->toBeNull();
});

it('returns null for an unsupported (non-raster) format', function () {
    $tenant = Tenant::factory()->create(['logo_path' => 'logos/acme.tiff']);

    expect(MailLogo::urlFor($tenant))->toBeNull();
});

it('renders the brand name as text when the logo is an SVG', function () {
    $tenant = Tenant::factory()->create(['brand_name' => 'Acme', 'logo_path' => 'logos/acme.svg']);

    $html = Blade::render('<x-cms::mail :tenant="$tenant">Body</x-cms::mail>', ['tenant' => $tenant]);

    expect($html)
        ->not->toContain('<img')
        ->toContain('Acme');
});

it('renders the dedicated PNG mail logo as an img in the layout', function () {
    $tenant = Tenant::factory()->create([
        'brand_name' => 'Acme',
        'logo_path' => 'logos/acme.svg',
        'mail_logo_path' => 'logos/acme-mail.png',
    ]);

    $html = Blade::render('<x-cms::mail :tenant="$tenant">Body</x-cms::mail>', ['tenant' => $tenant]);

    expect($html)
        ->toContain('<img')
        ->toContain('logos/acme-mail.png')
        ->toContain('alt="Acme"');
});
