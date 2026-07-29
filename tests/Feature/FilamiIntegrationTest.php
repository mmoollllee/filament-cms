<?php

/**
 * Optional Umami integration (mmoollllee/filami, require-dev here): the CMS
 * wires provisioning, dashboard widgets and the layout tracking snippet
 * automatically once the package is installed. These tests pin that wiring —
 * filami's attribute conventions must keep matching the tenants schema
 * (umami_website_id / name / primary_domain) — and that everything stays
 * inert without UMAMI_* credentials.
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Pages\Dashboard;
use Mmoollllee\Cms\Filament\Pages\Tenancy\EditTenantProfilePage;
use Mmoollllee\Cms\Filament\Widgets\ContentOverviewWidget;
use Mmoollllee\Cms\Filament\Widgets\RecentVersionsWidget;
use Mmoollllee\Filami\Filament\Pages\UmamiStatistics;
use Mmoollllee\Filami\Filament\Widgets\UmamiEventsWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;
use Mmoollllee\Filami\Jobs\ProvisionUmamiWebsite;
use Mmoollllee\Filami\Jobs\SyncUmamiWebsite;
use Workbench\App\Models\Tenant;

it('queues umami provisioning when a tenant is created', function () {
    filamiConfigured();
    Queue::fake();

    Tenant::factory()->create(['primary_domain' => 'acme-analytics.test']);

    Queue::assertPushed(ProvisionUmamiWebsite::class, 1);
});

it('stores the umami website id on the tenant end to end', function () {
    filamiConfigured();
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        // Provisioning looks the domain up before creating, so GET and POST
        // need separate answers.
        '*/api/websites*' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['id' => 'umami-uuid-1', 'name' => 'Acme', 'domain' => 'acme-analytics.test'])
            : Http::response(['data' => []]),
    ]);

    $tenant = Tenant::factory()->create(['primary_domain' => 'acme-analytics.test']);

    expect($tenant->fresh()->umami_website_id)->toBe('umami-uuid-1');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/websites')
        && $request['domain'] === 'acme-analytics.test');
});

it('pushes a sync when name or primary domain change', function () {
    filamiConfigured();
    Queue::fake();

    $tenant = Tenant::factory()->create(['primary_domain' => 'acme-analytics.test']);
    $tenant->forceFill(['umami_website_id' => 'umami-uuid-2'])->saveQuietly();

    $tenant->update(['primary_domain' => 'acme-relaunch.test']);
    $tenant->update(['brand_claim' => 'Not synced']);

    Queue::assertPushed(SyncUmamiWebsite::class, 1);
});

it('stays inert without umami credentials', function () {
    Queue::fake();

    Tenant::factory()->create();

    Queue::assertNotPushed(ProvisionUmamiWebsite::class);
});

it('keeps analytics off the dashboard and on its own page', function () {
    // Reach numbers answer a different question than "what should I work on",
    // and four of them pushed the changelog below the fold.
    $widgets = (new Dashboard)->getWidgets();

    expect($widgets)->not->toContain(UmamiStatsOverviewWidget::class)
        ->not->toContain(UmamiVisitorsChartWidget::class)
        ->not->toContain(UmamiTopPagesWidget::class)
        ->not->toContain(UmamiEventsWidget::class)
        ->and(array_search(ContentOverviewWidget::class, $widgets, true))
        ->toBeLessThan(array_search(RecentVersionsWidget::class, $widgets, true))
        ->and(UmamiStatistics::class)->toBeIn(panelPages());
});

it('renders the tracking snippet in the site layout for a linked tenant', function () {
    filamiConfigured();
    config()->set('filami.tracking.environments', ['*']);

    $tenant = Tenant::factory()->create([
        'primary_domain' => 'acme-analytics.test',
        'umami_website_id' => 'umami-uuid-9',
    ]);

    $html = Blade::render('<x-site.layout :tenant="$tenant">content</x-site.layout>', ['tenant' => $tenant]);

    expect($html)
        ->toContain('data-website-id="umami-uuid-9"')
        ->toContain('https://a.example.test/script.js');
});

it('keeps the site layout clean without umami config', function () {
    $tenant = Tenant::factory()->create();

    $html = Blade::render('<x-site.layout :tenant="$tenant">content</x-site.layout>', ['tenant' => $tenant]);

    expect($html)->not->toContain('data-website-id')
        // The event plumbing follows the tracker; neither may appear alone.
        ->not->toContain('window.filami');
});

it('ships the event plumbing alongside the tracking snippet', function () {
    filamiConfigured();
    config()->set('filami.tracking.environments', ['*']);

    $tenant = Tenant::factory()->create([
        'primary_domain' => 'acme-analytics.test',
        'umami_website_id' => 'umami-uuid-9',
    ]);

    $html = Blade::render('<x-site.layout :tenant="$tenant">content</x-site.layout>', ['tenant' => $tenant]);

    // One marker, matching the negative test above: whether the component
    // rendered is the CMS's business, what its script contains is filami's.
    expect($html)->toContain('window.filami');
});

it('tracks a tenant configured entirely from the panel, without env', function () {
    // Server and website id typed into "Seiten-Einstellungen"; no UMAMI_* set.
    config()->set('filami.tracking.environments', ['*']);

    $tenant = Tenant::factory()->create([
        'umami_url' => 'https://stats.example.test',
        'umami_website_id' => 'panel-uuid',
    ]);

    $html = Blade::render('<x-site.layout :tenant="$tenant">content</x-site.layout>', ['tenant' => $tenant]);

    expect($html)
        ->toContain('src="https://stats.example.test/script.js"')
        ->toContain('data-website-id="panel-uuid"');
});

it('adds the session recorder when a tenant enables replay', function () {
    config()->set('filami.tracking.environments', ['*']);

    $tenant = Tenant::factory()->create([
        'umami_url' => 'https://stats.example.test',
        'umami_website_id' => 'panel-uuid',
        'umami_replay' => true,
    ]);

    $html = Blade::render('<x-site.layout :tenant="$tenant">content</x-site.layout>', ['tenant' => $tenant]);

    expect($html)
        ->toContain('src="https://stats.example.test/script.js"')
        ->toContain('src="https://stats.example.test/recorder.js"');
});

it('offers the analytics fields on the tenant profile page', function () {
    $tenant = actingAsMarketingPanelAdmin();

    $fields = (new ReflectionMethod(EditTenantProfilePage::class, 'tenantFields'))
        ->invoke(app(EditTenantProfilePage::class));

    expect($fields)->toContain('umami_url')->toContain('umami_website_id')->toContain('umami_replay');

    Livewire::test(EditTenantProfilePage::class)
        ->assertOk()
        ->assertFormFieldExists('umami_url')
        ->assertFormFieldExists('umami_website_id')
        ->assertFormFieldExists('umami_replay');
});

it('persists a manually entered server and website id', function () {
    actingAsMarketingPanelAdmin();

    Livewire::test(EditTenantProfilePage::class)
        ->fillForm([
            'umami_url' => 'https://stats.example.test',
            'umami_website_id' => 'hand-typed',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    expect($tenant->umami_url)->toBe('https://stats.example.test')
        ->and($tenant->umami_website_id)->toBe('hand-typed');
});

it('persists the session recording toggle', function () {
    // A field that renders but is not fillable passes every form assertion
    // while silently discarding the value — which is exactly what happened in
    // the consumer apps before their models were updated.
    actingAsMarketingPanelAdmin();

    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['umami_replay' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Tenant::where('site_key', 'marketing')->firstOrFail()->umami_replay)->toBeTrue();
});
