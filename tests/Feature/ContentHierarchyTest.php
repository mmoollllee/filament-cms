<?php

/*
 * Parent-child pages (typical CMS nesting): the parent defines the path prefix,
 * the record only owns its last segment; renaming a parent moves the subtree;
 * the parent select never offers the record itself or its descendants.
 */

use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['site_key' => 'default', 'primary_domain' => 'hierarchy.test']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->parent = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ratgeber',
        'path' => '/ratgeber',
    ]);
});

it('derives a child page path from its parent', function () {
    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->parent->id,
        'title' => 'Erste Schritte',
    ]);

    expect($child->path)->toBe('/ratgeber/erste-schritte')
        ->and($child->slug)->toBe('erste-schritte');
});

it('rebases a typed path under the parent (the record only owns its last segment)', function () {
    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->parent->id,
        'title' => 'Egal',
        'path' => '/woanders/custom-segment',
    ]);

    expect($child->path)->toBe('/ratgeber/custom-segment');
});

it('moves the whole subtree when a parent is renamed', function () {
    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->parent->id,
        'title' => 'Erste Schritte',
    ]);

    $grandchild = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $child->id,
        'title' => 'Details',
    ]);

    $this->parent->update(['path' => '/wissen']);

    expect($child->refresh()->path)->toBe('/wissen/erste-schritte')
        ->and($grandchild->refresh()->path)->toBe('/wissen/erste-schritte/details');
});

it('keeps top-level pages untouched (no parent, typed path wins)', function () {
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt',
        'path' => '/kontakt-aufnehmen',
    ]);

    expect($page->path)->toBe('/kontakt-aufnehmen');
});

it('excludes the record itself and its descendants from the parent options', function () {
    $child = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $this->parent->id,
        'title' => 'Kind',
    ]);

    $grandchild = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'parent_id' => $child->id,
        'title' => 'Enkel',
    ]);

    $method = new ReflectionMethod(CatchAllContentResource::class, 'getParentOptions');

    $options = $method->invoke(null, $this->tenant, 'default.page', $child);

    expect($options)->toHaveKey($this->parent->id)      // ancestors stay available
        ->and($options)->not->toHaveKey($child->id)      // never itself…
        ->and($options)->not->toHaveKey($grandchild->id); // …or anything below it
});
