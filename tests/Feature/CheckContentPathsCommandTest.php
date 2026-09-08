<?php

/*
 * The read-only pre-deploy report: which rows would be rewritten by their next save, and
 * which of those cannot be, because another record already holds the corrected path. The
 * second group is the dangerous one — a save that raises the same validation error every
 * time, on a field the editor never touched — so it, and only it, fails the command.
 */

use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['site_key' => 'marketing', 'name' => 'Marketing']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('reports nothing while every stored path is a fixpoint', function () {
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
    ]);

    $this->artisan('cms:paths:check')
        ->expectsOutputToContain('Every content path is what the generator would store.')
        ->assertSuccessful();
});

it('reports a drifted row that could still move cleanly', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
    ]);

    // Past the generator, the way legacy rows and hand-written imports get there.
    $guide->forceFill(['path' => '/hilfe/erste-hilfe'])->saveQuietly();

    $this->artisan('cms:paths:check')
        ->expectsOutputToContain('1 row(s) would be rewritten by their next save.')
        ->expectsOutputToContain('None of them is blocked')
        ->assertSuccessful();
});

it('fails on a drifted row whose corrected path someone else already holds', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
    ]);

    expect($guide->path)->toBe('/ratgeber/erste-hilfe');

    $guide->forceFill(['path' => '/hilfe/erste-hilfe'])->saveQuietly();

    // /ratgeber/erste-hilfe is free again while the guide sits elsewhere, so a second
    // record can legitimately take it — and the first can then never be saved again.
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
        'slug' => 'erste-hilfe',
    ]);

    $this->artisan('cms:paths:check')
        ->expectsOutputToContain('1 of them cannot move')
        ->assertFailed();
});

it('scopes the check to one tenant', function () {
    // tenants.site_key is unique, so the second tenant is the workbench's other site.
    $other = Tenant::factory()->create(['site_key' => 'acme', 'name' => 'Zweiter']);

    $section = Content::create([
        'tenant_id' => $other->id,
        'content_type' => 'default.page',
        'title' => 'Hilfe',
        'path' => '/hilfe',
    ]);

    $child = Content::create([
        'tenant_id' => $other->id,
        'content_type' => 'default.page',
        'parent_id' => $section->id,
        'title' => 'Erste Schritte',
    ]);

    expect($child->path)->toBe('/hilfe/erste-schritte');

    $child->forceFill(['path' => '/woanders/erste-schritte'])->saveQuietly();

    $this->artisan('cms:paths:check', ['--tenant' => $this->tenant->id])
        ->expectsOutputToContain('Every content path is what the generator would store.')
        ->assertSuccessful();

    $this->artisan('cms:paths:check', ['--tenant' => $other->id])
        ->expectsOutputToContain('would be rewritten')
        ->assertSuccessful();
});
