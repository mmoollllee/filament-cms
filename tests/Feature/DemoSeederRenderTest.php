<?php

use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Tenant;
use Workbench\Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

// ── The filament-cms docs site (tenant A, host 127.0.0.1) ────────────────────

it('renders every public docs page (200)', function () {
    foreach (['/', '/features', '/blocks', '/customize', '/howto', '/howto/custom-blocks', '/howto/tiptap-extensions', '/imprint', '/privacy'] as $path) {
        $this->get("http://127.0.0.1{$path}")->assertOk();
    }
});

it('appends the self-documenting demo content (tenants, logins, feature matrix) to the home page', function () {
    $html = $this->get('http://127.0.0.1/')->assertOk()->getContent();

    expect($html)->toContain('<table')                 // the former DEMO.md tables, now seeded HTML
        ->and($html)->toContain('admin@example.test')  // logins table
        ->and($html)->toContain('site_key')            // tenants table
        ->and($html)->toContain('Feature');            // feature matrix
});

it('renders the rich-editor custom blocks on the homepage', function () {
    $html = $this->get('http://127.0.0.1/')->assertOk()->getContent();

    // navigationCardGroup → .nav-cards ; buttonGroup → .btn-group ; quick-start code.
    expect($html)->toContain('nav-cards')
        ->and($html)->toContain('btn-group')
        ->and($html)->toContain('composer require');
});

it('renders code snippets on the features page', function () {
    $html = $this->get('http://127.0.0.1/features')->assertOk()->getContent();

    expect($html)->toContain('<pre')                        // a code block rendered
        ->and($html)->toContain('tenantDomain')             // multi-tenancy snippet
        ->and($html)->toContain('fragment_model');          // fragments snippet
});

it('ships the home page with a real version history for the revisions demo', function () {
    $home = Workbench\App\Models\Content::where('path', '/')
        ->where('tenant_id', Tenant::where('site_key', 'marketing')->firstOrFail()->getKey())
        ->firstOrFail();

    // Initial version + two applied edits — enough for the Revisionen action
    // (hidden below 2) and a meaningful side-by-side diff.
    expect($home->versions()->count())->toBe(3)
        ->and(data_get($home->payload, 'hero.subtitle'))->toContain('full version history')
        ->and(data_get($home->firstVersion->contents, 'payload'))->not->toContain('full version history');

    // The frontend shows the LATEST applied state.
    expect($this->get('http://127.0.0.1/')->assertOk()->getContent())
        ->toContain('full version history');
});

it('documents drafts & preview on the features page and demos it via the seeded pending draft', function () {
    // Guests see the documentation section, but never the draft-only content.
    $html = $this->get('http://127.0.0.1/features')->assertOk()->getContent();

    expect($html)->toContain('Drafts &amp; Vorschau')
        ->and($html)->toContain('HasDraft')
        ->not->toContain('This section exists only in the pending draft');

    // Logged in + ?preview=1: the stashed extra section and the badge render.
    $this->actingAs(Workbench\App\Models\User::where('email', 'admin@example.test')->firstOrFail());

    $preview = $this->get('http://127.0.0.1/features?preview=1')->assertOk()->getContent();

    expect($preview)->toContain('This section exists only in the pending draft')
        ->and($preview)->toContain('Vorschau: Entwürfe sichtbar');
});

it('renders the block showcase: live listing, media image and code', function () {
    $html = $this->get('http://127.0.0.1/blocks')->assertOk()->getContent();

    expect($html)->toContain('Quick start')                 // listing items (marketing.service)
        ->and($html)->toContain('Custom blocks')
        ->and($html)->toContain('/demo-media.svg')          // media block image
        ->and($html)->toContain('listing-card');
});

it('renders a section\'s own content without a header preset', function () {
    // Regression: the section view used to swallow its content when no
    // header preset was picked — the legal pages exercise exactly that shape.
    $this->get('http://127.0.0.1/imprint')->assertOk()->assertSee('Imprint of the filament-cms demo');
});

it('renders the customization guide as seeded content', function () {
    $html = $this->get('http://127.0.0.1/customize')->assertOk()->getContent();

    expect($html)->toContain('DiscoversSiteBlueprints')      // site-extension snippet
        ->and($html)->toContain('HasPublishingStatus')       // model traits section
        ->and($html)->toContain('BasePanelProvider');        // panel section
});

it('renders the custom-blocks howto including the live app-registered hint block', function () {
    $html = $this->get('http://127.0.0.1/howto/custom-blocks')->assertOk()->getContent();

    expect($html)->toContain('hint-success')                 // the LIVE hint block (workbench-registered)
        ->and($html)->toContain('Living proof')
        ->and($html)->toContain('anonymousComponentPath');   // registration snippet
});

it('links the howto guides to the hub and renders the breadcrumb trail', function () {
    $html = $this->get('http://127.0.0.1/howto/custom-blocks')->assertOk()->getContent();

    // Breadcrumb: Home › HowTos › HowTo: Custom blocks (parent_id chain).
    expect($html)->toContain('aria-label="Breadcrumb"')
        ->and($html)->toContain('href="/howto"')
        ->and($html)->toContain('aria-current="page"');

    // …and the top-level hub page itself shows no trail.
    expect($this->get('http://127.0.0.1/howto')->getContent())->not->toContain('aria-label="Breadcrumb"');
});

it('renders the tiptap howto', function () {
    $html = $this->get('http://127.0.0.1/howto/tiptap-extensions')->assertOk()->getContent();

    expect($html)->toContain('htmlSpan')                     // JS extension snippet
        ->and($html)->toContain('RichContentPlugin')
        ->and($html)->toContain('filament:assets');
});

it('serves the demo media placeholder image', function () {
    $this->get('http://127.0.0.1/demo-media.svg')
        ->assertOk()
        ->assertHeader('content-type', 'image/svg+xml');
});

it('hides draft, scheduled and members-only content from guests', function () {
    $this->get('http://127.0.0.1/draft')->assertNotFound();
    $this->get('http://127.0.0.1/scheduled')->assertNotFound();
    $this->get('http://127.0.0.1/members')->assertNotFound();
});

// ── Second tenant (host localhost): branding inheritance ─────────────────────

it('inherits filament-cms branding on the second tenant', function () {
    // Acme GmbH leaves brand_name null → inherits "filament-cms" from tenant A.
    $this->get('http://localhost/')->assertOk()->assertSee('filament-cms');
});

it('renders the second tenant as an onepager composed of sections', function () {
    $html = $this->get('http://localhost/')->assertOk()->getContent();

    // All root default.section contents render stacked in the shell…
    expect($html)->toContain('onepager-demo-section')
        ->and($html)->toContain('Willkommen')
        ->and($html)->toContain('Leistungen')
        ->and($html)->toContain('Kontakt');

    // …and every section's own path serves that same shell.
    $this->get('http://localhost/leistungen')->assertOk()->assertSee('Willkommen');
    $this->get('http://localhost/kontakt')->assertOk()->assertSee('Leistungen');
});

// ── Navigation menus resolve per tenant ──────────────────────────────────────

it('resolves tenant-scoped header and footer menus', function () {
    $tenant = Tenant::where('site_key', 'marketing')->first();

    $header = \Mmoollllee\Cms\Models\Menu::linksForLocation('header', $tenant);
    $footer = \Mmoollllee\Cms\Models\Menu::linksForLocation('footer', $tenant);

    expect(collect($header)->pluck('label'))->toContain('Features', 'Blocks')
        ->and(collect($footer)->pluck('label'))->toContain('Imprint');
});

// ── Regression: the package footer component resolves the tenant itself ───────

it('renders the footer component without an explicit tenant', function () {
    $tenant = Tenant::where('site_key', 'marketing')->first();
    app(CurrentTenant::class)->set($tenant);

    $html = (string) view('cms::components.site.footer', ['legalLinks' => []])->render();

    expect($html)->toContain('filament-cms'); // $tenant->displayName()
});
