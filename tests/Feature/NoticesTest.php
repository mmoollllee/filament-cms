<?php

/*
 * Notice banners ("Hinweise", Cms::enableNotices()): a content type with a
 * publishing window that the package ships opt-in, its panel resource, and
 * <x-cms::notices /> rendering the live ones — every one of them in a member's
 * preview, the previewed one on top. The workbench enables them and places the
 * component above every demo page.
 */

use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Auth\GenericUser;
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
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Mmoollllee\Cms\Support\Content\Notices;
use Mmoollllee\Cms\Support\Preview\PreviewMode;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Tests\Fixtures\OverridingSites\Default\SiteExtension as OverridingDefaultExtension;
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
    $expired = noticeFixture($tenant, 'Alter Urlaub', ['publish_from' => now()->subYear(), 'publish_until' => now()->subMonths(11)]);
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

    $bannerTitles = function (string $url): array {
        preg_match_all('/<p class="notice-title">(.*?)<\/p>/', $this->get($url)->assertOk()->getContent(), $matches);

        return $matches[1];
    };

    expect($bannerTitles($page))->toBe(['Weihnachten', 'Tourausfall', 'Alter Urlaub', 'Entwurf'])
        // The "Vorschau" of the old notice puts it on top of the stack.
        ->and($bannerTitles($page.'&preview_focus='.$expired->getKey()))->toBe(['Alter Urlaub', 'Weihnachten', 'Tourausfall', 'Entwurf']);
});

it('treats a user of another guard like a guest', function () {
    $tenant = noticeSite();
    noticeFixture($tenant, 'Tourausfall');
    noticeFixture($tenant, 'Entwurf', ['publish_from' => null]);

    app(PreviewMode::class)->activate();

    // request()->user() may be any authenticatable — no CMS user, no preview.
    expect(Notices::shown($tenant, new GenericUser(['id' => 1]))->pluck('title')->all())->toBe(['Tourausfall']);

    app(PreviewMode::class)->deactivate();
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

it('follows a site that brings its own notice blueprint', function () {
    $tenant = actingAsMarketingPanelAdmin();

    // Stands in for a site extension that declares its own `default.notice`.
    app()->bind(NoticeBlueprint::class, fn (): NoticeBlueprint => new class extends NoticeBlueprint
    {
        protected ?string $pluralLabel = 'Meldungen';

        public function payloadFormComponents(): array
        {
            return [...parent::payloadFormComponents(), TextInput::make('payload.level')->label('Stufe')];
        }
    });
    // The registries memoize the blueprints the panel boot already resolved.
    app()->forgetInstance(SiteExtensionRegistry::class);
    app()->forgetInstance(ContentBlueprintRegistry::class);

    $notice = noticeFixture($tenant, 'Tourausfall');

    // The routes were registered as "hinweise" before any site was known.
    expect(NoticeResource::getSlug())->toBe('hinweise');

    Livewire::test(EditNotice::class, ['record' => $notice->getKey()])
        ->assertOk()
        ->assertFormFieldExists('payload.content')
        ->assertFormFieldExists('payload.level');
});

it('refuses an app default extension that would drop the notices', function () {
    $discoverOverridingSites = fn () => Cms::discoverSitesIn(dirname(__DIR__).'/Fixtures/OverridingSites', 'Mmoollllee\\Cms\\Tests\\Fixtures\\OverridingSites');

    $discoverOverridingSites();

    expect(fn () => (new SiteExtensionRegistry(app()))->all())
        ->toThrow(LogicException::class, "Cms::enableNotices() needs the package's default site extension");

    // Without notices, replacing the default extension is the app's business.
    Cms::flush();
    $discoverOverridingSites();

    expect((new SiteExtensionRegistry(app()))->all()['default'])->toBeInstanceOf(OverridingDefaultExtension::class);
});
