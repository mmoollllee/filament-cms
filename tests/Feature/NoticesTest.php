<?php

/*
 * Notice banners ("Hinweise", Cms::enableNotices()): a content type with a
 * publishing window that the package ships opt-in, its panel resource, and
 * <x-cms::notices /> rendering the live ones — every one of them in a member's
 * preview. The workbench enables them and places the component above every demo
 * page.
 */

use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;
use Mmoollllee\Cms\Filament\Resources\Notices\NoticeResource;
use Mmoollllee\Cms\Filament\Resources\Notices\Pages\EditNotice;
use Mmoollllee\Cms\Filament\Resources\Notices\Pages\ListNotices;
use Mmoollllee\Cms\Filament\Widgets\PendingContentWidget;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Sites\Notice\Blueprint as NoticeBlueprint;
use Mmoollllee\Cms\Support\Content\Notices;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

function noticeSite(): Tenant
{
    $tenant = Tenant::factory()->create(['primary_domain' => '127.0.0.1', 'site_key' => 'marketing']);

    Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Startseite',
        'path' => '/notice-fixture',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
    ]);

    return $tenant;
}

function noticeFixture(Tenant $tenant, string $title, array $attributes = []): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => NoticeBlueprint::KEY,
        'title' => $title,
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'payload' => ['content' => '<p>Text von '.$title.'</p>'],
        ...$attributes,
    ]);
}

function noticeEditor(Tenant $tenant): User
{
    $editor = User::factory()->create();
    $tenant->users()->attach($editor, ['role' => TenantUserRole::Editor->value]);

    return $editor;
}

it('registers the notice type with its own resource once enabled', function () {
    $blueprint = app(ContentBlueprintRegistry::class)->find(NoticeBlueprint::KEY, 'marketing');

    expect($blueprint)->not->toBeNull()
        ->and($blueprint->isRoutable())->toBeFalse()
        ->and($blueprint->expiresByDesign())->toBeTrue()
        ->and(Filament::getPanel('panel')->getResources())->toContain(NoticeResource::class)
        ->and(NoticeResource::getSlug())->toBe('hinweise');

    // The catch-all leaves the type to its own resource — no notices among the pages.
    app(CurrentTenant::class)->set(Tenant::factory()->create(['site_key' => 'marketing']));

    expect(CatchAllContentResource::getContentTypes())->not->toContain(NoticeBlueprint::KEY);
});

it('lists the notices beside the builder of the templates that place them', function () {
    Cms::enableNotices(on: ['acme.content.home', 'acme.content.kontakt']);

    expect(Cms::embedsForTemplate('acme.content.home', includeEveryPage: false)['contentTypes'])->toBe([NoticeBlueprint::KEY])
        ->and(Cms::embedsForTemplate('acme.content.kontakt', includeEveryPage: false)['contentTypes'])->toBe([NoticeBlueprint::KEY]);
});

it('renders the live notices, the latest window first', function () {
    $tenant = noticeSite();
    noticeFixture($tenant, 'Tourausfall', ['publish_from' => now()->subWeek()]);
    noticeFixture($tenant, 'Betriebsurlaub', ['publish_from' => now()->subDay(), 'publish_until' => now()->addWeek()]);
    noticeFixture($tenant, 'Alter Urlaub', ['publish_from' => now()->subYear(), 'publish_until' => now()->subMonths(11)]);
    noticeFixture($tenant, 'Weihnachten', ['publish_from' => now()->addMonths(3)]);
    noticeFixture($tenant, 'Leerer Hinweis', ['payload' => ['content' => '<p></p>']]);

    $this->get('http://127.0.0.1/notice-fixture')
        ->assertOk()
        ->assertSeeInOrder(['class="notices"', 'Betriebsurlaub', 'Text von Betriebsurlaub', 'Tourausfall'], false)
        ->assertDontSee('Alter Urlaub')
        ->assertDontSee('Weihnachten')
        // An empty banner is worse than none.
        ->assertDontSee('Leerer Hinweis');
});

it('renders nothing at all without a live notice', function () {
    $tenant = noticeSite();
    noticeFixture($tenant, 'Alter Urlaub', ['publish_until' => now()->subHour()]);

    $this->get('http://127.0.0.1/notice-fixture')
        ->assertOk()
        ->assertDontSee('class="notices"', false);
});

it('shows every notice to a previewing member, the live ones to everybody else', function () {
    $tenant = noticeSite();
    noticeFixture($tenant, 'Tourausfall');
    noticeFixture($tenant, 'Alter Urlaub', ['publish_from' => now()->subYear(), 'publish_until' => now()->subMonths(11)]);
    noticeFixture($tenant, 'Weihnachten', ['publish_from' => now()->addMonths(3)]);
    noticeFixture($tenant, 'Entwurf', ['publish_from' => null]);

    $page = 'http://127.0.0.1/notice-fixture?preview=1';

    // Guests get the live site, whatever the URL says.
    $this->get($page)
        ->assertOk()
        ->assertSee('Tourausfall')
        ->assertDontSee('Alter Urlaub')
        ->assertDontSee('Weihnachten')
        ->assertDontSee('Text von Entwurf');

    $this->actingAs(noticeEditor($tenant));

    $this->get($page)
        ->assertOk()
        ->assertSee('Tourausfall')
        ->assertSee('Alter Urlaub')
        ->assertSee('Weihnachten')
        ->assertSee('Text von Entwurf');
});

it('keeps notices off the site while not enabled', function () {
    $tenant = noticeSite();
    noticeFixture($tenant, 'Tourausfall');

    expect(Notices::shown($tenant))->toHaveCount(1);

    Cms::flush();

    expect(Notices::shown($tenant))->toBeEmpty();
});

it('manages notices in the panel and previews them in place', function () {
    $tenant = actingAsMarketingPanelAdmin();
    $expired = noticeFixture($tenant, 'Alter Urlaub', ['publish_from' => now()->subYear(), 'publish_until' => now()->subMonths(11)]);

    Livewire::test(ListNotices::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$expired]);

    $previewHandler = (string) Livewire::test(EditNotice::class, ['record' => $expired->getKey()])
        ->assertOk()
        ->instance()
        ->getAction('preview')
        ->getAlpineClickHandler();

    expect($previewHandler)->toContain('preview_focus='.$expired->getKey());

    // Its expiry was the plan — no task on the dashboard.
    Livewire::test(PendingContentWidget::class)->assertCanNotSeeTableRecords([$expired]);
});
