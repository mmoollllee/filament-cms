<?php

use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * Pins the brand-agnostic fallback contract of the package frontend shells:
 * they emit the section/navigation protocol the Alpine core binds against,
 * and they bind ONLY core component members — a consumer app calling
 * registerCmsFrontend(Alpine) without overrides and without an Alpine store
 * must render them error-free. Brand behavior (scroll hints, progress bar,
 * depth meter, measured header fitting) lives in consuming apps.
 *
 * No consumer renders these fallbacks in CI (apps override them, the
 * workbench demo ships its own shells) — this test is their only guard.
 */
function fallbackShellTenant(): Tenant
{
    $tenant = Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
    ]);

    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

/** @return array{0: \Workbench\App\Models\Content, 1: array<int, array<string, mixed>>} */
function fallbackShellSections(Tenant $tenant): array
{
    $sections = collect([
        ['path' => '/', 'title' => 'Start'],
        ['path' => '/kontakt', 'title' => 'Kontakt'],
    ])->map(fn (array $section) => Content::factory()->create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.section',
        'title' => $section['title'],
        'path' => $section['path'],
    ]));

    $payload = $sections->map(fn (Content $content) => [
        'content' => $content,
        'path' => $content->path,
        'anchor' => null,
        'navigation' => ['indicatorLabel' => $content->title],
        'title' => $content->title,
        'label' => $content->title,
    ])->all();

    return [$sections->first(), $payload];
}

it('renders the onepager fallback shell with the section protocol and without app-only bindings', function () {
    $tenant = fallbackShellTenant();
    [$currentContent, $sectionsPayload] = fallbackShellSections($tenant);

    $html = view('cms::frontend.onepager', [
        'tenant' => $tenant,
        'currentContent' => $currentContent,
        'currentContentView' => 'cms::content.page',
        'initialBreadcrumbs' => [],
        'initialNavigationContext' => ['indicatorLabel' => 'Start'],
        'contentEndpoint' => '/_content',
        'sectionsPayload' => $sectionsPayload,
        'sectionLinks' => [],
        'socialLinks' => [],
        'legalLinks' => [],
    ])->render();

    expect($html)
        // Architecture contract the siteOnepager core binds against:
        ->toContain('id="site-onepager"')
        ->toContain('data-content-endpoint="/_content"')
        ->toContain('x-data="siteOnepager($el)"')
        ->toContain('onepager-section')
        ->toContain('data-path="/kontakt"')
        ->toContain('data-role="header-indicator"')
        ->toContain('data-role="header-breadcrumbs"')
        ->toContain(__('cms::frontend.loading'))
        // Brand-agnostic: nothing here may bind app-side JS members or stores —
        // a consumer without overrides would throw otherwise.
        ->not->toContain('data-scroll-hint')
        ->not->toContain('$store.scroll')
        ->not->toContain('initHeaderBar')
        ->not->toContain('indicatorMeasure')
        ->not->toContain('breadcrumbMeasure')
        ->not->toContain('headerBar.');
});

it('renders the standalone floating header fallback with the child navigation binding', function () {
    $tenant = fallbackShellTenant();

    $html = view('cms::partials.floating-header', [
        'tenant' => $tenant,
        'isOnepager' => false,
        'initialNavigationContext' => ['indicatorLabel' => 'Über uns'],
        'sectionLinks' => [],
        'socialLinks' => [],
        'legalLinks' => [],
    ])->render();

    expect($html)
        ->toContain('siteChildNavigation($el')
        ->toContain('data-role="header-indicator"')
        ->toContain('x-text="currentIndicatorLabel()"')
        ->not->toContain('initHeaderBar')
        ->not->toContain('$store.scroll');
});

it('serves the fallback strings in english when the app locale is en', function () {
    $tenant = fallbackShellTenant();

    app()->setLocale('en');

    $flyout = view('cms::partials.header-flyout', [
        'sectionLinks' => [],
        'socialLinks' => [],
        'legalLinks' => [],
    ])->render();

    expect($flyout)
        ->toContain('No social links configured.')
        ->toContain('Main menu');

    $notFound = view('cms::errors.404', [
        'tenant' => $tenant,
        'requestedPath' => '/nope',
        'homeUrl' => '/',
        'resolveUrl' => 'http://localhost/_resolve404',
    ])->render();

    expect($notFound)->toContain('Page not found');
});
