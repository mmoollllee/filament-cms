<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['site_key' => 'marketing']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('generates a path and derives the slug for a routable type', function () {
    $content = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Über Uns',
    ]);

    expect($content->path)->toBe('/uber-uns')
        ->and($content->slug)->toBe('uber-uns');
});

it('keeps a non-routable type path-less but still slugged', function () {
    $content = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.note',
        'title' => 'Interne Notiz',
    ]);

    expect($content->path)->toBeNull()
        ->and($content->slug)->toBe('interne-notiz');
});

it('respects an explicit slug on a non-routable type', function () {
    $content = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.note',
        'title' => 'Interne Notiz',
        'slug' => 'eigener-slug',
    ]);

    expect($content->path)->toBeNull()
        ->and($content->slug)->toBe('eigener-slug');
});

it('allows multiple non-routable records (null paths do not collide)', function () {
    Content::create(['tenant_id' => $this->tenant->id, 'content_type' => 'marketing.note', 'title' => 'Notiz A']);
    Content::create(['tenant_id' => $this->tenant->id, 'content_type' => 'marketing.note', 'title' => 'Notiz B']);

    expect(Content::where('content_type', 'marketing.note')->whereNull('path')->count())->toBe(2);
});

it('resolvedPath returns the stored path for a routable type', function () {
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Über Uns',
    ]);

    expect($page->resolvedPath())->toBe('/uber-uns');
});

it('does not resolve a non-routable record by a stale leftover path', function () {
    $note = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'marketing.note',
        'title' => 'Interne Notiz',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
    ]);

    // Simulate a stale path left by a prior type-rename: a raw update bypasses the
    // GeneratesPathAndSlug saving hook that would otherwise null it.
    DB::table('contents')->where('id', $note->id)->update(['path' => '/asdf']);
    $note->refresh();

    // resolvedPath must reflect routability, not the leftover column.
    expect($note->resolvedPath())->toBeNull();

    // And the resolver must not serve the non-routable record via the catch-all route.
    expect(app(ContentResolver::class)->findByPath($this->tenant, '/asdf'))->toBeNull();
});
