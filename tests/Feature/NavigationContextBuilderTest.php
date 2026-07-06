<?php

use Illuminate\Support\Facades\DB;
use Mmoollllee\Cms\Support\Content\NavigationContextBuilder;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * Covers the navigation-context perf work: ancestorTrailFromPath() resolves a whole path chain
 * in one query (no N+1), and indicatorContext() returns exactly the same four fields as the full
 * build() — the onepager section payload can use it without the discarded breadcrumb work.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

function makePage(Tenant $tenant, string $title, string $path, ?int $parentId = null): Content
{
    return Content::factory()->create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => $title,
        'path' => $path,
        'parent_id' => $parentId,
    ]);
}

it('resolves a deep path-based ancestor trail in a single query', function () {
    makePage($this->tenant, 'A', '/a');
    makePage($this->tenant, 'B', '/a/b');
    makePage($this->tenant, 'C', '/a/b/c');
    $leaf = makePage($this->tenant, 'D', '/a/b/c/d');

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $labels = app(NavigationContextBuilder::class)->breadcrumbLabelsFor($leaf);

    $log = DB::connection()->getQueryLog();

    // Correct root→leaf trail...
    expect($labels)->toBe(['A', 'B', 'C', 'D']);

    // ...resolved with ONE ancestor query (the old code issued one per segment = 3 here).
    $ancestorQueries = array_filter($log, fn (array $q): bool => str_contains($q['query'], ' in ('));
    expect($ancestorQueries)->toHaveCount(1);
});

it('indicatorContext returns exactly the four fields build() exposes', function () {
    $content = makePage($this->tenant, 'Über uns', '/ueber-uns');

    $nav = app(NavigationContextBuilder::class);

    $fromFullBuild = collect($nav->build($this->tenant, $content))
        ->only(['indicatorLabel', 'rootPath', 'currentPath', 'homePath'])
        ->all();

    // Same key→value set (order is irrelevant for the JSON-keyed navigation payload).
    expect($nav->indicatorContext($this->tenant, $content))->toEqual($fromFullBuild);
});
