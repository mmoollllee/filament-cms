<?php

/*
 * The tenant sitemap: which URLs it lists and the <lastmod> each one carries.
 */

use Mmoollllee\Cms\Enums\ContentVisibility;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

function sitemapTenant(): Tenant
{
    return Tenant::factory()->create([
        'primary_domain' => '127.0.0.1',
        'site_key' => 'marketing',
    ]);
}

function sitemapPage(Tenant $tenant, string $path, string $title = 'Seite'): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => $title,
        'path' => $path,
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'blocks' => [],
    ]);
}

it('gives every URL a lastmod', function () {
    $tenant = sitemapTenant();
    sitemapPage($tenant, '/impressum');
    sitemapPage($tenant, '/datenschutz');

    $xml = $this->get('http://127.0.0.1/sitemap.xml')->assertOk()->getContent();

    expect(substr_count($xml, '<loc>'))->toBe(3)
        ->and(substr_count($xml, '<lastmod>'))->toBe(3);
});

it('emits lastmod as a W3C datetime', function () {
    $tenant = sitemapTenant();
    sitemapPage($tenant, '/impressum');

    $xml = $this->get('http://127.0.0.1/sitemap.xml')->getContent();

    expect($xml)->toMatch('/<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}<\/lastmod>/');
});

it('dates a page URL by that page own last edit', function () {
    $tenant = sitemapTenant();
    $page = sitemapPage($tenant, '/impressum');
    $page->forceFill(['updated_at' => now()->subMonth()])->saveQuietly();

    $xml = $this->get('http://127.0.0.1/sitemap.xml')->getContent();

    expect($xml)->toContain('<loc>http://127.0.0.1/impressum</loc>')
        ->and($xml)->toContain('<lastmod>'.$page->fresh()->updated_at->toAtomString().'</lastmod>');
});

/**
 * The homepage has no content row of its own, so it has to inherit the freshest edit
 * anywhere on the site — otherwise it would look permanently unchanged to a crawler.
 */
it('dates the homepage by the freshest edit anywhere on the site', function () {
    $tenant = sitemapTenant();

    $old = sitemapPage($tenant, '/impressum');
    $old->forceFill(['updated_at' => now()->subYear()])->saveQuietly();

    $fresh = sitemapPage($tenant, '/aktuelles');
    $fresh->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $xml = $this->get('http://127.0.0.1/sitemap.xml')->getContent();
    preg_match('/<loc>http:\/\/127\.0\.0\.1<\/loc>\s*<lastmod>([^<]+)<\/lastmod>/', $xml, $matches);

    expect($matches[1] ?? null)->toBe($fresh->fresh()->updated_at->toAtomString());
});

it('lets an app override where a content type keeps its editorial date', function () {
    $tenant = sitemapTenant();
    sitemapPage($tenant, '/impressum');

    app()->bind(
        \Mmoollllee\Cms\Http\Controllers\Frontend\SitemapController::class,
        fn ($app) => new class(
            $app->make(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class),
            $app->make(\Mmoollllee\Cms\Support\Content\ContentResolver::class),
            $app->make(\Mmoollllee\Cms\Sites\ContentBlueprintRegistry::class),
        ) extends \Mmoollllee\Cms\Http\Controllers\Frontend\SitemapController
        {
            protected function lastModifiedFor(\Mmoollllee\Cms\Contracts\Content $content): ?string
            {
                return '2020-01-01T00:00:00+00:00';
            }
        }
    );

    $xml = $this->get('http://127.0.0.1/sitemap.xml')->getContent();

    expect($xml)->toContain('<lastmod>2020-01-01T00:00:00+00:00</lastmod>')
        ->and(substr_count($xml, '<lastmod>2020-01-01T00:00:00+00:00</lastmod>'))->toBe(2);
});
