<?php

use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * Pins the "↳" title-indent rule of the shared content table: a row is only
 * indented under ancestors the SAME table lists. The unrestricted pages tree
 * indents nested pages; a type-restricted listing (machines, categories whose
 * parents are other types) shows a flat set without stray arrows.
 */
function indentTenant(): Tenant
{
    return Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
    ]);
}

it('indents nested rows in the unrestricted pages tree by listed depth', function () {
    $tenant = indentTenant();

    $root = Content::factory()->create(['tenant_id' => $tenant->getKey(), 'content_type' => 'default.page', 'title' => 'Root']);
    $child = Content::factory()->create(['tenant_id' => $tenant->getKey(), 'content_type' => 'default.page', 'title' => 'Kind', 'parent_id' => $root->getKey()]);
    $grandchild = Content::factory()->create(['tenant_id' => $tenant->getKey(), 'content_type' => 'default.page', 'title' => 'Enkel', 'parent_id' => $child->getKey()]);

    $resource = new class extends TenantScopedContentResource {};

    expect($resource::listedAncestorDepth($root))->toBe(0)
        ->and($resource::listedAncestorDepth($child))->toBe(1)
        ->and($resource::listedAncestorDepth($grandchild))->toBe(2);
});

it('shows no indent when the parent type is not listed by the table', function () {
    $tenant = indentTenant();

    $category = Content::factory()->create(['tenant_id' => $tenant->getKey(), 'content_type' => 'test.kategorie', 'title' => 'Kategorie']);
    $machine = Content::factory()->create(['tenant_id' => $tenant->getKey(), 'content_type' => 'test.maschine', 'title' => 'Maschine', 'parent_id' => $category->getKey()]);

    $machinesOnly = new class extends TenantScopedContentResource
    {
        protected static array $contentTypes = ['test.maschine'];
    };
    $categoriesOnly = new class extends TenantScopedContentResource
    {
        protected static array $contentTypes = ['test.kategorie'];
    };

    // Machine listing: parent is a category → not listed → flat row.
    expect($machinesOnly::listedAncestorDepth($machine))->toBe(0)
        // Category listing: parent chain leaves the listed type immediately.
        ->and($categoriesOnly::listedAncestorDepth($category))->toBe(0);
});

it('keeps the indent for same-type nesting in a type-restricted listing', function () {
    $tenant = indentTenant();

    $parent = Content::factory()->create(['tenant_id' => $tenant->getKey(), 'content_type' => 'default.page', 'title' => 'Eltern']);
    $child = Content::factory()->create(['tenant_id' => $tenant->getKey(), 'content_type' => 'default.page', 'title' => 'Kind', 'parent_id' => $parent->getKey()]);

    $pagesOnly = new class extends TenantScopedContentResource
    {
        protected static array $contentTypes = ['default.page'];
    };

    expect($pagesOnly::listedAncestorDepth($child))->toBe(1);
});

it('caps the indent at four levels', function () {
    $tenant = indentTenant();

    $current = null;
    foreach (range(0, 6) as $i) {
        $current = Content::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'content_type' => 'default.page',
            'title' => "Ebene {$i}",
            'parent_id' => $current?->getKey(),
        ]);
    }

    $resource = new class extends TenantScopedContentResource {};

    expect($resource::listedAncestorDepth($current))->toBe(4);
});
