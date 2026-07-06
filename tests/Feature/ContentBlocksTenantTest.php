<?php

use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

it('renders the frontend homepage without the undefined $tenant error', function () {
    // Regression: the package content/page.blade.php renders <x-site.content-blocks>
    // without passing a tenant. The component referenced an undeclared $tenant and
    // 500'd with "Undefined variable $tenant".
    $tenant = Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
    ]);

    Content::factory()->create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Startseite',
        'path' => '/',
    ]);

    $this->get('http://localhost/')->assertOk();
});

it('passes the resolved tenant down to listing blocks so listed items render', function () {
    // The second half of the bug: even with $tenant declared, content-blocks did not
    // forward it to the child block components, so listing blocks queried a null
    // tenant and rendered nothing.
    $tenant = Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
    ]);

    Content::factory()->create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Gelistete Seite',
        'path' => '/gelistet',
    ]);

    $page = Content::factory()->create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Übersicht',
        'path' => '/uebersicht',
        'blocks' => [
            ['type' => 'listing', 'data' => ['content_type' => 'default.page']],
        ],
    ]);

    // Simulate the broken caller: render the package template WITHOUT an explicit
    // tenant. The component must resolve it from the content / CurrentTenant and
    // forward it to the listing block.
    app(CurrentTenant::class)->set($tenant);

    $html = (string) view('cms::content.page', [
        'content' => $page,
        'navigationContext' => null,
    ])->render();

    expect($html)->toContain('Gelistete Seite');
});
