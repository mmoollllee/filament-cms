<?php

/*
 * The form rule and the saving hook ask PathConflicts the same question about the same
 * row, one right after the other, with nothing writing in between — so the walk runs once
 * and the second caller reads the answer.
 *
 * The cache is a claim about the table, though, and a write invalidates it. Two records
 * created in one request produce the same question key (both are new, same tenant, same
 * parent, same path), and without invalidation the second would be told the first's path
 * is still free — straight into the unique index the whole guard exists to keep away from.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mmoollllee\Cms\Support\Content\PathConflicts;
use Workbench\App\Models\Content;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

it('answers the same question once', function () {
    $page = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Seite',
        'path' => '/seite',
    ]);

    $page->path = '/seite-neu';

    $conflicts = app(PathConflicts::class);

    DB::enableQueryLog();
    $conflicts->firstConflict($page);
    $first = count(DB::getQueryLog());

    $conflicts->firstConflict($page);
    $second = count(DB::getQueryLog()) - $first;
    DB::disableQueryLog();

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0);
});

it('forgets its answers as soon as anything is written', function () {
    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Erste',
        'path' => '/gleicher-pfad',
    ]);

    // Same question key as the row above — new record, same tenant, same path.
    expect(fn () => Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Zweite',
        'path' => '/gleicher-pfad',
    ]))->toThrow(ValidationException::class);
});
