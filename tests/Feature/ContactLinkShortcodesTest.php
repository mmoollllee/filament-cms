<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mmoollllee\Cms\Support\Shortcodes;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Tenant;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The contact-link shortcodes register via the package's extension hook
    // (always on — laravel-spamprotect is a package dependency).
    Shortcodes::reset();

    // The spamprotect encrypt components hash this key with sha256, so any non-empty
    // value works; set it here so the in-process pest bootstrap has one.
    config()->set('spamprotect.key', 'testbench-spamprotect-key');
});

it('exposes the contact-link merge tags in the default label list', function () {
    expect(Shortcodes::mergeTags())
        ->toHaveKeys(['contact_email_link', 'contact_phone_link']);
});

it('renders [contact_email_link] as a spam-protected token without leaking the address', function () {
    $tenant = Tenant::factory()->create(['contact_email' => 'spam@example.test']);
    app(CurrentTenant::class)->set($tenant);

    $result = Shortcodes::render('[contact_email_link]');

    expect($result)
        ->toContain('data-spamprotect-token=')
        ->not->toContain('spam@example.test');
});

it('renders [contact_phone_link] as a spam-protected token', function () {
    $tenant = Tenant::factory()->create(['contact_phone' => '+49 7304 80392-0']);
    app(CurrentTenant::class)->set($tenant);

    $result = Shortcodes::render('[contact_phone_link]');

    expect($result)
        ->toContain('data-spamprotect-token=')
        ->not->toContain('+49 7304 80392-0');
});

it('passes the class attribute through to the rendered link', function () {
    $tenant = Tenant::factory()->create(['contact_email' => 'spam@example.test']);
    app(CurrentTenant::class)->set($tenant);

    expect(Shortcodes::render('[contact_email_link class="btn"]'))->toContain('btn');
});

it('renders empty when the tenant has no contact value configured', function () {
    $tenant = Tenant::factory()->create(['contact_email' => null, 'contact_phone' => null]);
    app(CurrentTenant::class)->set($tenant);

    expect(Shortcodes::render('[contact_email_link]'))->toBe('')
        ->and(Shortcodes::render('[contact_phone_link]'))->toBe('');
});

it('exposes the contact-link merge tag values', function () {
    $tenant = Tenant::factory()->create(['contact_email' => 'spam@example.test']);
    app(CurrentTenant::class)->set($tenant);

    expect(Shortcodes::mergeTagValues())
        ->toHaveKeys(['contact_email_link', 'contact_phone_link']);
});
