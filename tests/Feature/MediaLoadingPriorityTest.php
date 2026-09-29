<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriver;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * The first media a page renders is its hero, usually the Largest Contentful Paint element.
 * It must load right away; everything after it stays lazy. A section fetched on scroll is
 * below the fold, so nothing in it jumps the queue.
 */

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

function renderMediaBlock(array $data): string
{
    return Blade::render('<x-block::media :data="$data" />', [
        'data' => [...$data, 'active' => true],
    ]);
}

function priorityPage(): Content
{
    $tenant = Tenant::factory()->create([
        'primary_domain' => '127.0.0.1',
        'site_key' => 'marketing',
    ]);

    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Referenzen',
        'path' => '/referenzen',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'blocks' => [
            ['type' => 'media', 'data' => ['active' => true, 'media_path' => 'content-blocks/hero.jpg']],
            ['type' => 'media', 'data' => ['active' => true, 'media_path' => 'content-blocks/detail.jpg']],
        ],
    ]);
}

/**
 * The attributes of the <img> whose src contains the given file name.
 */
function imageTagFor(string $html, string $fileName): string
{
    preg_match('#<img[^>]*'.preg_quote($fileName, '#').'[^>]*>#s', $html, $match);

    return $match[0] ?? '';
}

it('loads the first image eagerly with high priority and every later one lazily', function () {
    $first = renderMediaBlock(['media_path' => 'content-blocks/hero.jpg']);
    $second = renderMediaBlock(['media_path' => 'content-blocks/detail.jpg']);

    expect($first)
        ->toContain('loading="eager"')
        ->toContain('fetchpriority="high"')
        ->not->toContain('decoding="async"')
        ->and($second)
        ->toContain('loading="lazy"')
        ->toContain('decoding="async"')
        ->not->toContain('fetchpriority');
});

it('lets a leading video take the priority so the image after it stays lazy', function () {
    renderMediaBlock(['media_path' => 'content-blocks/hero.mp4']);

    expect(renderMediaBlock(['media_path' => 'content-blocks/detail.jpg']))
        ->toContain('loading="lazy"')
        ->not->toContain('fetchpriority');
});

it('leaves the priority to a hero the template already loads eagerly', function () {
    Blade::render('<x-site.image media="content-blocks/hero.jpg" loading="eager" />');

    expect(renderMediaBlock(['media_path' => 'content-blocks/detail.jpg']))
        ->toContain('loading="lazy"')
        ->not->toContain('fetchpriority');
});

it('prioritizes the first image of a server-rendered page', function () {
    priorityPage();

    $html = $this->get('http://127.0.0.1/referenzen')->assertOk()->getContent();

    expect(imageTagFor($html, 'hero.jpg'))->toContain('fetchpriority="high"')
        ->and(imageTagFor($html, 'detail.jpg'))->toContain('loading="lazy"');
});

it('keeps every image of a lazily fetched fragment lazy', function () {
    priorityPage();

    $html = $this->get('http://127.0.0.1/_content?path=/referenzen')->assertOk()->getContent();

    expect(imageTagFor($html, 'hero.jpg'))
        ->toContain('loading="lazy"')
        ->not->toContain('fetchpriority');
});

it('falls back to the still the library extracted from a video as its poster', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/clip.mp4');
    $media = $item->getFirstMedia($item->getMediaLibraryCollectionName());
    $media->mime_type = 'video/mp4';
    $media->generated_conversions = [CmsMediaLibraryDriver::RENDERED_CONVERSION => true];
    $media->save();

    Storage::disk('public')->put($media->getPathRelativeToRoot(CmsMediaLibraryDriver::RENDERED_CONVERSION), 'still');

    expect(renderMediaBlock(['media_path' => $item->getKey()]))
        ->toContain('poster="'.$media->getUrl(CmsMediaLibraryDriver::RENDERED_CONVERSION).'"');
});

it('renders no poster while the extracted still is missing from the disk', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/clip.mp4');
    $media = $item->getFirstMedia($item->getMediaLibraryCollectionName());
    $media->mime_type = 'video/mp4';
    $media->generated_conversions = [CmsMediaLibraryDriver::RENDERED_CONVERSION => true];
    $media->save();

    expect(renderMediaBlock(['media_path' => $item->getKey()]))->not->toContain('poster=');
});

it('prefers an explicit poster over the extracted still', function () {
    expect(renderMediaBlock([
        'media_path' => 'content-blocks/hero.mp4',
        'poster_path' => 'content-blocks/poster.jpg',
    ]))->toContain('poster="/storage/content-blocks/poster.jpg"');
});
