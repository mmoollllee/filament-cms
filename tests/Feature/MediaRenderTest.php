<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * Frontend rendering with media-library refs: the same views/accessors that
 * served legacy paths must serve item ids — media block, <x-site.image>,
 * og:image chain, branding cascade (favicon incl. inheritance).
 */

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

it('renders the media block from a library item id with central alt text', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, attributes: ['alt_text' => 'Zentraler Alt-Text']);

    $rendered = Blade::render('<x-block::media :data="$data" />', [
        'data' => ['media_path' => $item->getKey(), 'active' => true],
    ]);

    expect($rendered)
        ->toContain('pic.png')
        ->toContain('alt="Zentraler Alt-Text"')
        ->toContain('loading="lazy"');
});

it('lets the block-level alt override win over the item alt text', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, attributes: ['alt_text' => 'Zentraler Alt-Text']);

    $rendered = Blade::render('<x-block::media :data="$data" />', [
        'data' => ['media_path' => $item->getKey(), 'media_alt' => 'Lokaler Alt-Text', 'active' => true],
    ]);

    expect($rendered)->toContain('alt="Lokaler Alt-Text"');
});

it('still renders the media block from a legacy path', function () {
    $rendered = Blade::render('<x-block::media :data="$data" />', [
        'data' => ['media_path' => 'content-blocks/legacy.jpg', 'media_alt' => 'Alt', 'active' => true],
    ]);

    expect($rendered)->toContain('/storage/content-blocks/legacy.jpg');
});

it('renders <x-site.image> with lazy loading for library refs and plain img for legacy paths', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, attributes: ['alt_text' => 'Bild']);

    $library = Blade::render('<x-site.image :media="$ref" class="w-full" />', ['ref' => $item->getKey()]);
    $legacy = Blade::render('<x-site.image :media="$ref" alt="Alt" />', ['ref' => '2020/01/x.jpg']);

    expect($library)->toContain('loading="lazy"')->toContain('decoding="async"')
        ->toContain('alt="Bild"')->toContain('class="w-full"')
        ->and($legacy)->toContain('/storage/2020/01/x.jpg')->toContain('alt="Alt"');
});

it('emits the per-content og:image from a library id as an absolute URL', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $item = makeLibraryImage($tenant);

    $content = Content::factory()->for($tenant)->create([
        'meta' => ['og_image' => $item->getKey()],
    ]);

    $rendered = Blade::render('<x-site.seo-head :content="$content" :tenant="$tenant" />', [
        'content' => $content,
        'tenant' => $tenant,
    ]);

    expect($rendered)
        ->toContain('property="og:image"')
        ->toContain('http://localhost/storage/');
});

it('keeps the legacy og_image_url and the tenant default as fallbacks', function () {
    $tenant = Tenant::factory()->create(['default_og_image_path' => 'branding/og-default.png']);
    app(CurrentTenant::class)->set($tenant);

    $legacyContent = Content::factory()->for($tenant)->create([
        'meta' => ['og_image_url' => '/storage/2020/01/legacy-og.png'],
    ]);
    $bareContent = Content::factory()->for($tenant)->create();

    $legacyRendered = Blade::render('<x-site.seo-head :content="$content" :tenant="$tenant" />', [
        'content' => $legacyContent, 'tenant' => $tenant,
    ]);
    $fallbackRendered = Blade::render('<x-site.seo-head :content="$content" :tenant="$tenant" />', [
        'content' => $bareContent, 'tenant' => $tenant,
    ]);

    expect($legacyRendered)->toContain('http://localhost/storage/2020/01/legacy-og.png')
        ->and($fallbackRendered)->toContain('http://localhost/storage/branding/og-default.png');
});

it('resolves branding assets stored as item ids, inheritance included', function () {
    $branding = Tenant::factory()->create();
    $item = makeLibraryImage($branding, 'fixtures/favicon.png');
    $branding->update(['favicon_path' => (string) $item->getKey()]);

    $satellite = Tenant::factory()->create(['favicon_path' => null]);
    app(CurrentTenant::class)->set($satellite);

    $rendered = Blade::render('<x-site.favicon />');

    expect($rendered)->toContain('rel="icon"')->toContain('favicon.png')
        ->and($branding->resolvedFaviconUrl())->toStartWith('http://localhost/storage/');
});

it('resolves the mail logo from a library item id via its MIME type', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/mail-logo.png');
    $tenant->update(['mail_logo_path' => (string) $item->getKey()]);

    // pathinfo() on an id has no extension — the raster check must use the
    // item's MIME type, otherwise every library-managed mail logo vanishes.
    expect($tenant->resolvedMailLogoUrl())
        ->not->toBeNull()
        ->toContain('mail-logo.png');
});

it('never recreates default folders from the picker path after an editor deleted them', function () {
    $tenant = Tenant::factory()->create();

    $folder = \Mmoollllee\Cms\Support\Media\MediaFolders::ensure(\Mmoollllee\Cms\Support\Media\MediaFolders::PAGES, $tenant);
    $folder->delete();
    \Mmoollllee\Cms\Support\Media\MediaFolders::flush();

    // find() (the defaultFolder closure path) must not resurrect…
    expect(\Mmoollllee\Cms\Support\Media\MediaFolders::find(\Mmoollllee\Cms\Support\Media\MediaFolders::PAGES, $tenant))->toBeNull()
        ->and(\RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder::query()->count())->toBe(0);

    // …while ensure() (the import path) may create again.
    expect(\Mmoollllee\Cms\Support\Media\MediaFolders::ensure(\Mmoollllee\Cms\Support\Media\MediaFolders::PAGES, $tenant))->not->toBeNull();
});

it('renders classic uploads unchanged when the library is disabled', function () {
    Cms::disableMediaLibrary();

    $rendered = Blade::render('<x-block::media :data="$data" />', [
        'data' => ['media_path' => 'content-blocks/classic.jpg', 'active' => true],
    ]);

    expect($rendered)->toContain('/storage/content-blocks/classic.jpg');
});
