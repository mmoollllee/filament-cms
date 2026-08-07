<?php

/*
 * The topbar "Öffnen" button (cms::filament.header-actions, hooked before the
 * global search): it targets the frontend page of the record currently open in
 * the panel — the page's own URL, the parent page for non-routable types — and
 * falls back to the site's homepage everywhere else.
 */

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Support\FrontendLinkResolver;
use Mmoollllee\Cms\Models\Redirect;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

/**
 * The REAL registered route for the given panel URL, bound to a request — what
 * the resolver sees during a full (non-SPA) page render. Matching through the
 * router rather than hand-building a Route keeps the Filament page class on the
 * route action, which is where the resolver reads the resource from.
 */
function frontendLinkTestRoute(string $url): Route
{
    return app('router')->getRoutes()->match(Request::create($url));
}

function frontendLinkPage(Tenant $tenant, string $path = '/datenschutz'): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Datenschutz',
        'path' => $path,
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subWeek(),
    ]);
}

/** The edit route of the catch-all content resource for the given record. */
function frontendLinkContentRoute(Content $record): Route
{
    return frontendLinkTestRoute(route('filament.panel.resources.contents.edit', [
        'tenant' => $record->tenant_id,
        'record' => $record,
    ]));
}

test('resolver points the button at the page currently open in the panel', function () {
    $page = frontendLinkPage($this->tenant);

    expect(FrontendLinkResolver::forRoute(frontendLinkContentRoute($page)))
        ->toBe(['url' => 'http://localhost/datenschutz', 'label' => 'Öffnen']);
});

test('resolver falls back to the parent page for a non-routable record', function () {
    $parent = frontendLinkPage($this->tenant, '/leistungen');

    // marketing.note is non-routable: no path of its own, so the button opens
    // the page that embeds it — the same target as its "Vorschau".
    $note = Content::create([
        'tenant_id' => $this->tenant->id,
        'parent_id' => $parent->id,
        'content_type' => 'marketing.note',
        'title' => 'Hinweis',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subWeek(),
    ]);

    expect($note->resolvedPath())->toBeNull()
        ->and(FrontendLinkResolver::forRoute(frontendLinkContentRoute($note))['url'])
        ->toBe('http://localhost/leistungen');
});

test('resolver resolves records of a site-extension resource too', function () {
    $parent = frontendLinkPage($this->tenant, '/services');

    $service = Content::create([
        'tenant_id' => $this->tenant->id,
        'parent_id' => $parent->id,
        'content_type' => 'marketing.service',
        'title' => 'Beratung',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subWeek(),
    ]);

    // Own resource (Workbench\App\Sites\Marketing\Service\Resource) — the resolver
    // reads it off the route's page class, so a site extension needs no wiring.
    $route = frontendLinkTestRoute(route('filament.panel.resources.services.edit', [
        'tenant' => $this->tenant,
        'record' => $service,
    ]));

    expect(FrontendLinkResolver::forRoute($route)['url'])->toBe('http://localhost/services');
});

test('resolver falls back to the homepage for records without a frontend page', function () {
    $redirect = Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/alt',
        'to_path' => '/neu',
        'status_code' => 301,
        'is_active' => true,
    ]);

    // Redirect has no frontend page of its own — and the resolver must not even
    // query for it, since its model does not implement HasFrontendUrl.
    $route = frontendLinkTestRoute(route('filament.panel.resources.redirects.edit', [
        'tenant' => $this->tenant,
        'record' => $redirect,
    ]));

    expect(FrontendLinkResolver::forRoute($route))
        ->toBe(['url' => 'http://localhost', 'label' => 'Öffnen']);
});

test('resolver does not query for resources whose records have no frontend page', function () {
    $redirect = Redirect::create([
        'tenant_id' => $this->tenant->id,
        'from_path' => '/alt',
        'to_path' => '/neu',
        'status_code' => 301,
        'is_active' => true,
    ]);

    $route = frontendLinkTestRoute(route('filament.panel.resources.redirects.edit', [
        'tenant' => $this->tenant,
        'record' => $redirect,
    ]));

    DB::enableQueryLog();
    FrontendLinkResolver::forRoute($route);

    expect(DB::getQueryLog())->toBeEmpty();
});

test('resolver falls back to the homepage on pages without a record', function () {
    $route = frontendLinkTestRoute(route('filament.panel.pages.dashboard', [
        'tenant' => $this->tenant,
    ]));

    expect(FrontendLinkResolver::forRoute($route)['url'])->toBe('http://localhost');
});


test('resolver falls back to the homepage for a record of another tenant', function () {
    $other = Tenant::where('site_key', 'acme')->firstOrFail();
    $foreign = frontendLinkPage($other, '/fremde-seite');

    // The resource's tenant-scoped base query never returns it, so the panel of
    // one site cannot link into another site's frontend.
    expect(FrontendLinkResolver::forRoute(frontendLinkContentRoute($foreign))['url'])
        ->toBe('http://localhost');
});

test('topbar view renders the resolved link for the open record in a new tab', function () {
    $page = frontendLinkPage($this->tenant);

    // Mirror a full (non-SPA) page render of the content edit page, where the
    // current request route reflects the open record.
    request()->setRouteResolver(fn () => frontendLinkContentRoute($page));

    expect(view('cms::filament.header-actions')->render())
        ->toContain('http://localhost/datenschutz')
        ->toContain('target="_blank"')
        ->toContain('Öffnen');
});

test('content edit page renders the topbar link to the record frontend page', function () {
    $page = frontendLinkPage($this->tenant);

    // End-to-end guard: the unit tests above build routes by hand, so only a real
    // request pins that Filament's route name and record key actually resolve.
    $this->get(route('filament.panel.resources.contents.edit', [
        'tenant' => $this->tenant,
        'record' => $page,
    ]))
        ->assertOk()
        ->assertSee('http://127.0.0.1/datenschutz', escape: false);
});
