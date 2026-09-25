<?php

/**
 * The content table's status column: the badge says WHETHER a record is visible
 * right now, the description underneath WHEN — its planned publishing window. Rows
 * where nothing is planned (unpublished, live without an end) stay calm, so page
 * lists do not repeat a start date in every row.
 */

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ListContents;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    Carbon::setTestNow('2026-09-25 12:00:00');
});

function statusTablePage(Tenant $tenant, array $window): Content
{
    static $counter = 0;
    $counter++;

    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Status-Fixture '.$counter,
        'path' => '/status-fixture-'.$counter,
        'visibility' => ContentVisibility::Public,
        ...$window,
    ]);
}

it('shows the planned window under the status badge', function () {
    $scheduled = statusTablePage($this->tenant, ['publish_from' => '2026-10-01 10:00:00', 'publish_until' => '2026-10-15 18:00:00']);
    $scheduledOpenEnd = statusTablePage($this->tenant, ['publish_from' => '2026-10-01 10:00:00']);
    $liveUntil = statusTablePage($this->tenant, ['publish_from' => '2026-09-01 08:00:00', 'publish_until' => '2026-09-30 20:00:00']);
    $expired = statusTablePage($this->tenant, ['publish_from' => '2025-10-25 08:42:00', 'publish_until' => '2025-11-25 07:42:00']);

    Livewire::test(ListContents::class)
        ->assertTableColumnHasDescription('resolved_status', '01.10.2026 10:00 – 15.10.2026 18:00', $scheduled)
        ->assertTableColumnHasDescription('resolved_status', 'ab 01.10.2026 10:00', $scheduledOpenEnd)
        ->assertTableColumnHasDescription('resolved_status', '01.09.2026 08:00 – 30.09.2026 20:00', $liveUntil)
        ->assertTableColumnHasDescription('resolved_status', '25.10.2025 08:42 – 25.11.2025 07:42', $expired);
});

it('describes nothing where nothing is planned', function () {
    $liveOpenEnd = statusTablePage($this->tenant, ['publish_from' => '2026-09-01 08:00:00']);
    $unpublished = statusTablePage($this->tenant, ['publish_from' => null]);

    Livewire::test(ListContents::class)
        ->assertTableColumnHasDescription('resolved_status', null, $liveOpenEnd)
        ->assertTableColumnHasDescription('resolved_status', null, $unpublished);
});
