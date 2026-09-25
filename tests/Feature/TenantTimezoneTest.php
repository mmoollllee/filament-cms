<?php

/**
 * Local time in the panel: timestamps stay UTC in the database and in PHP, but
 * editors read and type the wall-clock time of their site — the tenant's
 * `timezone` column. "bis 18:00" in a German summer has to end the window at
 * 16:00 UTC, and every sentence that names a time has to say what the picker shows.
 */

use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Fields\PublishingFields;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Widgets\PendingContentWidget;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Support\Tenancy\TenantTimezone;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();

    Carbon::setTestNow('2026-09-25 12:00:00');
});

function localTimePage(Tenant $tenant, array $window): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Ortszeit-Fixture',
        'path' => '/ortszeit-fixture',
        'visibility' => ContentVisibility::Public,
        ...$window,
    ]);
}

it('resolves the timezone of a site and falls back to the app timezone', function () {
    expect(TenantTimezone::for($this->tenant))->toBe('Europe/Berlin')
        ->and(TenantTimezone::for(null))->toBe('UTC');

    // A typo in the tenant record must not take the panel down.
    $this->tenant->timezone = 'Europe/Suppingen';
    expect(TenantTimezone::for($this->tenant))->toBe('UTC');

    $this->tenant->timezone = '';
    expect(TenantTimezone::for($this->tenant))->toBe('UTC');
});

it('hands the timezone of the current site to filament', function () {
    expect(FilamentTimezone::get())->toBe('Europe/Berlin');

    $this->tenant->timezone = 'Europe/London';
    expect(FilamentTimezone::get())->toBe('Europe/London');

    app(CurrentTenant::class)->forget();
    expect(FilamentTimezone::get())->toBe('UTC');
});

it('shows the publishing window in local time and saves it back unchanged', function () {
    $page = localTimePage($this->tenant, ['publish_from' => '2026-10-01 08:00:00', 'publish_until' => '2026-12-01 17:00:00']);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        // Summer time in October, winter time in December.
        ->assertSet('data.publish_from', '2026-10-01 10:00')
        ->assertSet('data.publish_until', '2026-12-01 18:00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()->publish_from->format('Y-m-d H:i'))->toBe('2026-10-01 08:00')
        ->and($page->fresh()->publish_until->format('Y-m-d H:i'))->toBe('2026-12-01 17:00');
});

it('stores what the editor types as local time', function () {
    $page = localTimePage($this->tenant, ['publish_from' => '2026-10-01 08:00:00']);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->set('data.publish_until', '2026-10-02 18:00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()->publish_until->format('Y-m-d H:i'))->toBe('2026-10-02 16:00');
});

it('names local times in the publishing sentence', function () {
    // Get hands the window over in the app timezone.
    expect(PublishingFields::effectDescription('2026-10-01 08:00:00', '2026-12-01 17:00:00'))
        ->toContain('geht am 01.10.2026 um 10:00 Uhr automatisch online')
        ->toContain('wird am 01.12.2026 um 18:00 Uhr wieder ausgeblendet');
});

it('names local times in the to-do list and in the draft notice', function () {
    $scheduled = localTimePage($this->tenant, ['publish_from' => '2026-10-01 08:00:00']);

    Livewire::test(PendingContentWidget::class)
        ->assertOk()
        ->assertTableColumnStateSet('deadline', 'ab 01.10.2026 10:00', $scheduled);

    $scheduled->update(['publish_from' => '2026-09-01 08:00:00']);
    $scheduled->stashDraft(['title' => 'Geparkte Änderung']);

    Livewire::test(EditContent::class, ['record' => $scheduled->getKey()])
        ->assertOk()
        ->assertSee('Entwurf vom 25.09.2026 14:00 Uhr geladen');
});
