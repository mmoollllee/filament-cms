<?php

/*
 * The read-only pre-deploy report: which rows would be rewritten by their next save, and
 * which of those cannot be, because another record already holds the corrected path. The
 * second group is the dangerous one — a save that raises the same validation error every
 * time, on a field the editor never touched — so it, and only it, fails the command.
 */

use Illuminate\Support\Facades\DB;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Content\ContentResolver;
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

it('moves a drifted row to its generated path with --fix, leaving a redirect behind', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
    ]);

    $guide->forceFill(['path' => '/hilfe/erste-hilfe'])->saveQuietly();

    $this->artisan('cms:paths:check', ['--fix' => true])
        ->expectsOutputToContain('1 row(s) moved to their generated path')
        ->assertSuccessful();

    expect($guide->fresh()->path)->toBe('/ratgeber/erste-hilfe')
        ->and(Redirect::query()->where('from_path', '/hilfe/erste-hilfe')->exists())->toBeTrue();

    $this->artisan('cms:paths:check')
        ->expectsOutputToContain('Every content path is what the generator would store.')
        ->assertSuccessful();
});

it('moves a drifted row together with the subtree below it', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
    ]);

    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Verband',
        'path' => '/verband',
    ]);

    // The page follows the guide's stored address, so only the guide reads as drifted —
    // and moving it carries the page along, the way an edit of the guide would.
    DB::table('contents')->where('id', $page->id)->update(['parent_id' => $guide->id, 'path' => '/hilfe/erste-hilfe/verband']);
    $guide->forceFill(['path' => '/hilfe/erste-hilfe'])->saveQuietly();

    $this->artisan('cms:paths:check', ['--fix' => true])
        ->expectsOutputToContain('1 row(s) moved to their generated path')
        ->assertSuccessful();

    expect($guide->fresh()->path)->toBe('/ratgeber/erste-hilfe')
        ->and($page->fresh()->path)->toBe('/ratgeber/erste-hilfe/verband');
});

it('serves a drifted row at its stored address until --fix moves it', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
    ]);

    $guide->forceFill(['path' => '/hilfe/erste-hilfe'])->saveQuietly();

    // One address, not two: routing reads the stored path, the generated one is not
    // served on the side until the row actually moves there.
    $resolver = app(ContentResolver::class);

    expect($resolver->findByPath($this->tenant, '/hilfe/erste-hilfe')?->getKey())->toBe($guide->getKey())
        ->and($resolver->findByPath($this->tenant, '/ratgeber/erste-hilfe'))->toBeNull();
});

it('leaves a blocked row where it is with --fix, and fails', function () {
    $guide = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
    ]);

    $guide->forceFill(['path' => '/hilfe/erste-hilfe'])->saveQuietly();

    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.guide',
        'title' => 'Erste Hilfe',
        'slug' => 'erste-hilfe',
    ]);

    $this->artisan('cms:paths:check', ['--fix' => true])->assertFailed();

    expect($guide->fresh()->path)->toBe('/hilfe/erste-hilfe');
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
