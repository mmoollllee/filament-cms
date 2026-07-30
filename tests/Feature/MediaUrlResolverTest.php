<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Support\AssetUrlResolver;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use RalphJSmit\Filament\Explore\Data\FileData;
use Workbench\App\Models\Tenant;

/*
 * The resolver is the single seam every view/accessor renders media through:
 * ints resolve via the Spatie Media API (item id → URL/conversion/srcset/alt),
 * strings keep the pre-library path behavior. AssetUrlResolverTest pins the
 * legacy half; this file pins the media half + the shared façade.
 */

beforeEach(function () {
    Storage::fake('public');
});

it('resolves a library item id to its media URL', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);

    $url = MediaUrlResolver::url($item->getKey());

    expect($url)->toContain('/storage/')
        ->toContain('pic.png')
        ->and(MediaUrlResolver::url((string) $item->getKey()))->toBe($url)
        ->and(AssetUrlResolver::resolve($item->getKey()))->toBe($url);
});

it('falls back to the original URL while a conversion is not generated yet', function () {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);

    // Conversion jobs are held back by the queue fake — `og` does not exist.
    expect(MediaUrlResolver::url($item->getKey(), 'og'))
        ->toBe(MediaUrlResolver::url($item->getKey()));
});

it('resolves a conversion strictly, without falling back to the original', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);

    expect(MediaUrlResolver::conversionUrl($item->getKey(), 'thumb'))
        ->toBe(MediaUrlResolver::url($item->getKey(), 'thumb'))
        // The contrast that gives conversionUrl() its reason to exist: url() hands
        // back the original for a missing conversion, which would put a video file
        // into an <img src> when the caller wanted a still.
        ->and(MediaUrlResolver::conversionUrl($item->getKey(), 'nope'))->toBeNull()
        ->and(MediaUrlResolver::url($item->getKey(), 'nope'))->toBe(MediaUrlResolver::url($item->getKey()))
        ->and(MediaUrlResolver::conversionUrl('2020/01/pic.jpg', 'thumb'))->toBeNull()
        ->and(MediaUrlResolver::conversionUrl(null, 'thumb'))->toBeNull();
});

it('keeps legacy paths, URLs and the FileUpload array quirk working', function () {
    expect(MediaUrlResolver::url('2020/01/pic.jpg'))->toBe('/storage/2020/01/pic.jpg')
        ->and(MediaUrlResolver::url('https://example.test/x.jpg'))->toBe('https://example.test/x.jpg')
        ->and(MediaUrlResolver::url(['2020/01/pic.jpg']))->toBe('/storage/2020/01/pic.jpg')
        ->and(MediaUrlResolver::url(null))->toBeNull()
        ->and(MediaUrlResolver::url(''))->toBeNull();
});

it('returns null for unknown item ids instead of throwing', function () {
    expect(MediaUrlResolver::url(999999))->toBeNull()
        ->and(MediaUrlResolver::alt(999999))->toBeNull()
        ->and(MediaUrlResolver::isVideo(999999))->toBeFalse();
});

it('exposes central alt text and detects videos by item mime or legacy extension', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, attributes: ['alt_text' => 'Bagger im Einsatz']);

    expect(MediaUrlResolver::alt($item->getKey()))->toBe('Bagger im Einsatz')
        ->and(MediaUrlResolver::isVideo($item->getKey()))->toBeFalse()
        ->and(MediaUrlResolver::isLibraryRef($item->getKey()))->toBeTrue()
        ->and(MediaUrlResolver::isLibraryRef('2020/01/pic.jpg'))->toBeFalse();

    $item->getItem()->update(['mime_type' => 'video/mp4']);
    MediaUrlResolver::flush();

    expect(MediaUrlResolver::isVideo($item->getKey()))->toBeTrue()
        ->and(MediaUrlResolver::isVideo('legacy/clip.MP4'))->toBeTrue()
        ->and(MediaUrlResolver::isVideo('legacy/photo.jpg'))->toBeFalse();
});

it('generates srcset from responsive conversions and degrades without a disk base URL', function () {
    config(['media-library.queue_conversions_by_default' => false]);

    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/responsive.png');

    $srcset = MediaUrlResolver::srcset($item->getKey());

    expect($srcset)->not->toBeNull()
        ->and($srcset)->toContain('w');

    // Private-disk installs (no `url` on the disk) must degrade to plain
    // conversion URLs — srcset would point nowhere.
    config(['filesystems.disks.public.url' => null]);
    MediaUrlResolver::flush();

    expect(MediaUrlResolver::srcset($item->getKey()))->toBeNull();
});

it('preloads every ref of a block tree with a single query', function () {
    $tenant = Tenant::factory()->create();
    $first = makeLibraryImage($tenant, 'fixtures/a.png');
    $second = makeLibraryImage($tenant, 'fixtures/b.png');
    MediaUrlResolver::flush();

    $blocks = [
        ['type' => 'media', 'data' => ['media_path' => $first->getKey(), 'media_alt' => null]],
        ['type' => 'section', 'data' => ['blocks' => [
            ['type' => 'media', 'data' => ['media_path' => $second->getKey()]],
        ]]],
    ];

    DB::enableQueryLog();
    MediaUrlResolver::preload($blocks);
    $queriesAfterPreload = count(DB::getQueryLog());

    MediaUrlResolver::url($first->getKey());
    MediaUrlResolver::url($second->getKey());
    MediaUrlResolver::alt($second->getKey());

    expect(count(DB::getQueryLog()))->toBe($queriesAfterPreload);
    DB::disableQueryLog();
});

/*
 * MediaPicker key hashes.
 *
 * Filament renders builder block previews from the RAW form state, where a
 * MediaPicker holds base64(AES("media-library-item:<id>")) — the picker's own
 * FileData key — instead of the id it persists on save. Before normalize() knew
 * that shape, every library-backed block preview rendered /storage/<hash>.
 */

it('resolves a MediaPicker key hash to the same item as its id', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, attributes: ['alt_text' => 'Bagger']);

    $hash = FileData::encryptKeyHash('media-library-item:'.$item->getKey());

    expect(MediaUrlResolver::normalize($hash))->toBe((int) $item->getKey())
        ->and(MediaUrlResolver::isLibraryRef($hash))->toBeTrue()
        ->and(MediaUrlResolver::url($hash))->toBe(MediaUrlResolver::url($item->getKey()))
        ->and(MediaUrlResolver::alt($hash))->toBe('Bagger');
});

it('resolves a key hash nested in the FileUpload array quirk', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);

    $hash = FileData::encryptKeyHash('media-library-item:'.$item->getKey());

    expect(MediaUrlResolver::url([$hash]))->toBe(MediaUrlResolver::url($item->getKey()));
});

it('never renders a key hash as a storage path, not even for a missing item', function () {
    $hash = FileData::encryptKeyHash('media-library-item:999999');

    // The id is decoded but resolves to nothing — null, never /storage/<hash>.
    expect(MediaUrlResolver::normalize($hash))->toBe(999999)
        ->and(MediaUrlResolver::url($hash))->toBeNull();
});

it('leaves folder keys and unrelated ciphertexts alone', function () {
    // Only item keys map to an id; a folder key is not a media ref.
    $folder = FileData::encryptKeyHash('media-library-folder:1');

    expect(MediaUrlResolver::normalize($folder))->toBe($folder)
        ->and(MediaUrlResolver::normalize('not-base64-at-all'))->toBe('not-base64-at-all');
});

it('keeps legacy paths and URLs out of the decryption path', function () {
    // A dot rules a string out before any decryption is attempted — legacy paths
    // always carry an extension, hashes never do.
    expect(MediaUrlResolver::normalize('2020/01/pic.jpg'))->toBe('2020/01/pic.jpg')
        ->and(MediaUrlResolver::normalize('https://example.test/x.jpg'))->toBe('https://example.test/x.jpg')
        ->and(MediaUrlResolver::url('branding/defaults/logo.svg'))->toBe('/storage/branding/defaults/logo.svg');
});

it('memoizes the deterministic hash lookup and clears it on flush', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $hash = FileData::encryptKeyHash('media-library-item:'.$item->getKey());

    expect(MediaUrlResolver::normalize($hash))->toBe((int) $item->getKey());

    MediaUrlResolver::flush();

    // Still correct after the memo is dropped — the cipher is deterministic, so
    // the same hash has to decode to the same id on a cold cache.
    expect(MediaUrlResolver::normalize($hash))->toBe((int) $item->getKey());
});
