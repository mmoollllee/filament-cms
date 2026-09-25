<?php

/**
 * Times in the panel are the app's — `app.timezone`, which the apps read from
 * APP_TIMEZONE (Europe/Berlin). Nothing converts: the pickers show what is
 * stored, a typed "18:00" is stored as 18:00, and every sentence that names a
 * time names the same one. Run in Europe/Berlin here, as the apps do.
 */

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Fields\PublishingFields;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Widgets\PendingContentWidget;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    config(['app.timezone' => 'Europe/Berlin']);
    date_default_timezone_set('Europe/Berlin');

    $this->tenant = actingAsMarketingPanelAdmin();

    Carbon::setTestNow('2026-09-25 12:00:00');
});

afterEach(function () {
    date_default_timezone_set('UTC');
});

function panelTimesPage(Tenant $tenant, array $window): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Zeit-Fixture',
        'path' => '/zeit-fixture',
        'visibility' => ContentVisibility::Public,
        ...$window,
    ]);
}

it('shows the publishing window as stored and saves it back unchanged', function () {
    $page = panelTimesPage($this->tenant, ['publish_from' => '2026-10-01 10:00:00', 'publish_until' => '2026-12-01 18:00:00']);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertOk()
        ->assertSet('data.publish_from', '2026-10-01 10:00')
        ->assertSet('data.publish_until', '2026-12-01 18:00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()->publish_from->format('Y-m-d H:i'))->toBe('2026-10-01 10:00')
        ->and($page->fresh()->publish_until->format('Y-m-d H:i'))->toBe('2026-12-01 18:00');
});

it('stores the time the editor types', function () {
    $page = panelTimesPage($this->tenant, ['publish_from' => '2026-10-01 10:00:00']);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->set('data.publish_until', '2026-10-02 18:00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()->publish_until->format('Y-m-d H:i'))->toBe('2026-10-02 18:00');
});

it('names the same times in the sentence above the pickers', function () {
    $page = panelTimesPage($this->tenant, ['publish_from' => '2026-10-01 10:00:00', 'publish_until' => '2026-12-01 18:00:00']);
    $sentence = 'geht am 01.10.2026 um 10:00 Uhr automatisch online und wird am 01.12.2026 um 18:00 Uhr wieder ausgeblendet';

    expect(PublishingFields::effectDescription($page->publish_from, $page->publish_until))->toContain($sentence);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertSee($sentence)
        ->set('data.title', 'Neuer Titel')
        ->assertSee($sentence);

    // Live since 11:00 — at 12:00 the page is visible.
    $page->update(['publish_from' => '2026-09-25 11:00:00', 'publish_until' => null]);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertSee('Für Besucher sichtbar.')
        ->assertDontSee('noch nicht sichtbar');
});

it('names the same times in the to-do list and in the draft notice', function () {
    $scheduled = panelTimesPage($this->tenant, ['publish_from' => '2026-10-01 10:00:00']);

    Livewire::test(PendingContentWidget::class)
        ->assertOk()
        ->assertTableColumnStateSet('deadline', 'ab 01.10.2026 10:00', $scheduled);

    $scheduled->update(['publish_from' => '2026-09-01 08:00:00']);
    $scheduled->stashDraft(['title' => 'Geparkte Änderung']);

    Livewire::test(EditContent::class, ['record' => $scheduled->getKey()])
        ->assertOk()
        ->assertSee('Entwurf vom 25.09.2026 12:00 Uhr geladen');
});
