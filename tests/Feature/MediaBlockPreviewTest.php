<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use RalphJSmit\Filament\Explore\Data\FileData;
use Workbench\App\Models\Tenant;

/*
 * The builder preview (blocks::media.preview) has different constraints from the
 * frontend view MediaRenderTest pins: it must stay cheap (derivatives, never the
 * original), never render a <video> that paints black, survive the raw form state
 * Filament hands it (MediaPicker key hashes, not ids), and bring its own height —
 * the layout presets it echoes are written for the frontend section grid.
 */

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

function renderMediaBlockPreview(array $data = []): string
{
    return view('blocks::media.preview', array_merge([
        'media_path' => null,
        'poster_path' => null,
        'layout_preset_ids' => [],
        'title' => null,
    ], $data))->render();
}

it('renders the empty state without a medium', function () {
    expect(renderMediaBlockPreview())->toContain('Kein Medium ausgewählt');
});

it('renders a preview derivative of an image rather than the original', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, attributes: ['alt_text' => 'Bagger']);

    $html = renderMediaBlockPreview(['media_path' => $item->getKey(), 'title' => 'Bagger']);

    expect($html)->toContain(MediaUrlResolver::url($item->getKey(), '400'))
        ->and($html)->toContain('alt="Bagger"');
});

it('renders from a MediaPicker key hash exactly as from the id', function () {
    // Filament previews from the raw form state, where the picker holds this hash.
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);

    $hash = FileData::encryptKeyHash('media-library-item:'.$item->getKey());

    expect(renderMediaBlockPreview(['media_path' => $hash]))
        ->toBe(renderMediaBlockPreview(['media_path' => $item->getKey()]));
});

it('never leaks a key hash into the markup when the item is gone', function () {
    $hash = FileData::encryptKeyHash('media-library-item:999999');
    $html = renderMediaBlockPreview(['media_path' => $hash]);

    expect($html)->not->toContain($hash)
        ->and($html)->not->toContain('/storage/')
        ->and($html)->toContain('Kein Medium ausgewählt');
});

it('shows a legacy video as a video element, never as an image source', function () {
    $html = renderMediaBlockPreview(['media_path' => 'content-blocks/clip.mp4']);

    expect($html)->toContain('<video')
        ->and($html)->toContain('/storage/content-blocks/clip.mp4#t=0.1')
        ->and($html)->toContain('type="video/mp4"')
        ->and($html)->not->toContain('<img');
});

it('prefers the block poster over loading the video', function () {
    $html = renderMediaBlockPreview([
        'media_path' => 'content-blocks/clip.mp4',
        'poster_path' => 'content-blocks/poster.jpg',
    ]);

    expect($html)->toContain('<img')
        ->and($html)->toContain('/storage/content-blocks/poster.jpg')
        ->and($html)->toContain('Video')
        ->and($html)->not->toContain('<video');
});

it('keeps a legacy path out of the poster slot when no conversion exists', function () {
    // conversionUrl() must not fall back to the original here — a .mp4 in an
    // <img src> is exactly the bug the strict lookup exists to prevent.
    $html = renderMediaBlockPreview(['media_path' => 'content-blocks/clip.mp4']);

    expect($html)->not->toContain('<img src="/storage/content-blocks/clip.mp4"');
});

it('carries a baseline height the layout presets cannot supply', function () {
    expect(renderMediaBlockPreview(['media_path' => 'content-blocks/photo.jpg']))
        ->toContain('min-h-40');
});

it('echoes the layout preset classes so the preview mirrors the frontend', function () {
    $tenant = Tenant::factory()->create();
    $preset = \Mmoollllee\Cms\Models\LayoutPreset::query()->create([
        'tenant_id' => $tenant->getKey(),
        'title' => 'Bild begrenzt',
        'classes' => 'max-h-[60vh] rounded-panel',
        'scope' => ['section-child'],
    ]);

    expect(renderMediaBlockPreview([
        'media_path' => 'content-blocks/photo.jpg',
        'layout_preset_ids' => [$preset->getKey()],
    ]))->toContain('max-h-[60vh] rounded-panel');
});
