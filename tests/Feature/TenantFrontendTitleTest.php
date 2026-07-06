<?php

/*
 * The document-title composition lives in ONE place (InheritsBranding):
 * frontendTitleFor() for records, frontendTitleForValues() for unsaved form
 * state (the SeoFields placeholder) — so the panel placeholder can never
 * drift from what the frontend layout actually renders.
 */

use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'site_key' => 'default',
        'primary_domain' => 'titles.test',
        'brand_name' => 'Beispiel GmbH',
        'brand_claim' => 'Klarer Claim',
    ]);
});

it('composes "{title} – {site name}" for regular pages', function () {
    expect($this->tenant->frontendTitleForValues('Leistungen', '/leistungen'))
        ->toBe('Leistungen – Beispiel GmbH');
});

it('falls back to the tenant default title', function (?string $title, ?string $path) {
    expect($this->tenant->frontendTitleForValues($title, $path))
        ->toBe('Beispiel GmbH – Klarer Claim');
})->with([
    'homepage path' => ['Leistungen', '/'],
    '"Start" title' => ['Start', '/start'],
    '"Home" title' => ['Home', '/home'],
    'blank title' => ['  ', '/leistungen'],
]);

it('prefers a configured default_seo_title in the fallback', function () {
    $this->tenant->update(['default_seo_title' => 'Konfigurierter Titel']);

    expect($this->tenant->frontendTitleForValues(null, '/'))->toBe('Konfigurierter Titel');
});

it('uses the same composition for content records via frontendTitleFor()', function () {
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt',
        'path' => '/kontakt',
    ]);

    expect($this->tenant->frontendTitleFor($page))->toBe('Kontakt – Beispiel GmbH')
        ->and($this->tenant->frontendTitleFor(null))->toBe('Beispiel GmbH – Klarer Claim');
});
