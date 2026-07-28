<?php

/**
 * Dashboard widgets: what the panel's landing page tells an editor.
 *
 * - ContentOverviewWidget groups counts by MANAGING RESOURCE (so the labels
 *   match the sidebar and one resource never yields two identical cards) and
 *   summarises publication states in ContentStatus vocabulary — "unveröffentlicht",
 *   never "Entwurf", which belongs to the draft stash alone.
 * - PendingContentWidget is the to-do list: draft stashes, scheduled and expired
 *   content, and nothing else. It hides itself when there is nothing to do.
 */

use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Widgets\ContentOverviewWidget;
use Mmoollllee\Cms\Filament\Widgets\PendingContentWidget;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

function dashboardPage(Tenant $tenant, array $attributes = []): Content
{
    static $counter = 0;
    $counter++;

    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Dashboard-Fixture '.$counter,
        'path' => '/dashboard-fixture-'.$counter,
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        ...$attributes,
    ]);
}

/** The widget's view data, which is where the labelling decisions live. */
function contentOverviewData(): array
{
    $widget = new ContentOverviewWidget;

    $method = new ReflectionMethod($widget, 'getViewData');
    $method->setAccessible(true);

    return $method->invoke($widget);
}

it('labels content cards with the managing resource, not the content_type slug', function () {
    dashboardPage($this->tenant);

    $labels = array_column(contentOverviewData()['stats'], 'label');

    // The slug is `default.page`; a naive ucfirst() of the last segment produced
    // "Page" while the sidebar said "Seiten".
    expect($labels)->not->toContain('Page')
        ->and($labels)->not->toContain('default.page');

    foreach ($labels as $label) {
        expect($label)->not->toContain('_')
            ->and($label)->not->toContain('.');
    }
});

it('emits one card per resource even when the resource owns several content types', function () {
    dashboardPage($this->tenant);
    dashboardPage($this->tenant, ['content_type' => 'default.section', 'path' => '/a-section']);

    $stats = contentOverviewData()['stats'];
    $labels = array_column($stats, 'label');

    expect($labels)->toHaveCount(count(array_unique($labels)));
});

it('links every card that has an accessible resource', function () {
    dashboardPage($this->tenant);

    $stats = contentOverviewData()['stats'];

    expect($stats)->not->toBeEmpty();

    foreach ($stats as $stat) {
        expect($stat['url'])->toBeString();
    }
});

it('summarises publication states without calling unpublished content a draft', function () {
    dashboardPage($this->tenant);                                        // published
    dashboardPage($this->tenant, ['publish_from' => null]);              // unpublished
    dashboardPage($this->tenant, ['publish_from' => now()->addWeek()]);  // scheduled

    $summary = contentOverviewData()['summary'];

    expect($summary)
        ->toContain('unveröffentlicht')
        ->and($summary)->toContain('geplant')
        ->and($summary)->not->toContain('Entwurf')
        ->and($summary)->not->toContain('Entwürfe');
});

it('pluralises the content total correctly', function () {
    // The demo seeder already fills the tenant, so start from a known baseline.
    Content::query()->where('tenant_id', $this->tenant->id)->delete();

    dashboardPage($this->tenant);

    expect(contentOverviewData()['summary'])->toStartWith('1 Inhalt ');

    dashboardPage($this->tenant);

    expect(contentOverviewData()['summary'])->toStartWith('2 Inhalte ');
});

it('lists drafts, scheduled and expired content in the to-do widget', function () {
    $scheduled = dashboardPage($this->tenant, ['publish_from' => now()->addWeek()]);
    $expired = dashboardPage($this->tenant, [
        'publish_from' => now()->subMonth(),
        'publish_until' => now()->subDay(),
    ]);

    $stashed = dashboardPage($this->tenant);
    $stashed->stashDraft(['title' => 'Geparkte Änderung']);

    Livewire::test(PendingContentWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$scheduled, $expired, $stashed]);
});

it('keeps settled content out of the to-do widget', function () {
    $published = dashboardPage($this->tenant);
    $unpublished = dashboardPage($this->tenant, ['publish_from' => null]);

    // Something must qualify, or the widget hides and asserts nothing.
    dashboardPage($this->tenant, ['publish_from' => now()->addWeek()]);

    Livewire::test(PendingContentWidget::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$published, $unpublished]);
});

it('never leaks another tenant into the to-do widget', function () {
    $own = dashboardPage($this->tenant, ['publish_from' => now()->addWeek()]);

    $foreignTenant = Tenant::factory()->create(['site_key' => 'default', 'primary_domain' => 'foreign-todo.test']);
    $foreign = dashboardPage($foreignTenant, ['publish_from' => now()->addWeek()]);

    Livewire::test(PendingContentWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('hides the to-do widget when nothing is pending', function () {
    // The demo seeder ships a scheduled page — clear it, or "nothing pending"
    // is never actually the case under test.
    Content::query()->where('tenant_id', $this->tenant->id)->delete();

    dashboardPage($this->tenant);

    expect(PendingContentWidget::canView())->toBeFalse();

    dashboardPage($this->tenant, ['publish_from' => now()->addWeek()]);

    expect(PendingContentWidget::canView())->toBeTrue();
});

it('hides the to-do widget without a resolved tenant', function () {
    app(CurrentTenant::class)->forget();

    expect(PendingContentWidget::canView())->toBeFalse();
});
