<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Workbench\App\Models\Tenant;

/*
 * cms:media:prune deletes files, so its contract is defined as much by what it
 * MUST NOT touch as by what it removes. The disk is shared with non-library
 * uploads (tenant branding, content-block videos, legacy WordPress trees), and
 * a media row whose conversions are merely pending must survive a scan.
 */

beforeEach(function () {
    Storage::fake('public');
    // Conversions would otherwise run inline and write real derivatives; the
    // tests plant exactly the files they assert on.
    Queue::fake();
});

/**
 * A library image's underlying Spatie media row. The item stores into its own
 * per-item collection, so the no-argument `getFirstMedia()` (default
 * collection) finds nothing.
 */
function libraryMedia(Tenant $tenant): Media
{
    $item = makeLibraryImage($tenant);

    return $item->getFirstMedia($item->getMediaLibraryCollectionName());
}

/**
 * The three directories the default path generator assigns to a media row.
 *
 * @return array{original: string, conversions: string, responsive: string}
 */
function mediaDirectories(Media $media): array
{
    return [
        'original' => $media->getKey().'/',
        'conversions' => $media->getKey().'/conversions/',
        'responsive' => $media->getKey().'/responsive-images/',
    ];
}

it('deletes a conversion left behind by a format change', function () {
    $tenant = Tenant::factory()->create();
    $media = libraryMedia($tenant);
    $directories = mediaDirectories($media);

    // What a jpg-era run wrote, next to what the webp definition writes today.
    $stale = $directories['conversions'].'pic-responsive.jpg';
    $current = $directories['conversions'].'pic-responsive.webp';
    Storage::disk('public')->put($stale, 'old');
    Storage::disk('public')->put($current, 'new');

    $this->artisan('cms:media:prune --force')->assertSuccessful();

    expect(Storage::disk('public')->exists($stale))->toBeFalse()
        ->and(Storage::disk('public')->exists($current))->toBeTrue();
});

it('deletes srcset candidates the media row no longer registers', function () {
    $tenant = Tenant::factory()->create();
    $media = libraryMedia($tenant);
    $directories = mediaDirectories($media);

    $registered = 'pic___responsive_400_300.webp';
    $dropped = 'pic___responsive_4032_3024.jpg';

    Storage::disk('public')->put($directories['responsive'].$registered, 'keep');
    Storage::disk('public')->put($directories['responsive'].$dropped, 'drop');

    $media->responsive_images = ['responsive' => ['urls' => [$registered]]];
    $media->save();

    $this->artisan('cms:media:prune --force')->assertSuccessful();

    expect(Storage::disk('public')->exists($directories['responsive'].$registered))->toBeTrue()
        ->and(Storage::disk('public')->exists($directories['responsive'].$dropped))->toBeFalse();
});

it('keeps the original the media row names', function () {
    $tenant = Tenant::factory()->create();
    $media = libraryMedia($tenant);

    $this->artisan('cms:media:prune --force')->assertSuccessful();

    expect(Storage::disk('public')->exists($media->getKey().'/'.$media->file_name))->toBeTrue();
});

it('deletes a numeric directory with no media row behind it', function () {
    $tenant = Tenant::factory()->create();
    makeLibraryImage($tenant);

    Storage::disk('public')->put('99999/ghost.png', cmsTestPngBytes());
    Storage::disk('public')->put('99999/conversions/ghost-thumb.png', cmsTestPngBytes());

    $this->artisan('cms:media:prune --force')->assertSuccessful();

    expect(Storage::disk('public')->exists('99999/ghost.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('99999/conversions/ghost-thumb.png'))->toBeFalse();
});

it('never touches non-library files sharing the disk', function () {
    $tenant = Tenant::factory()->create();
    makeLibraryImage($tenant);

    // Tenant branding, block uploads and downloads: all outside the library's
    // `{id}/` layout.
    $untouched = [
        'branding/demo/logo.png',
        'content-blocks/hero.mp4',
        'downloads/prospekt.pdf',
    ];

    foreach ($untouched as $path) {
        Storage::disk('public')->put($path, 'keep');
    }

    $this->artisan('cms:media:prune --force')->assertSuccessful();

    foreach ($untouched as $path) {
        expect(Storage::disk('public')->exists($path))->toBeTrue("[{$path}] must survive");
    }
});

it('deletes the uncapped original-level srcset once the conversion has its own', function () {
    $tenant = Tenant::factory()->create();
    $media = libraryMedia($tenant);
    $directories = mediaDirectories($media);

    // What `media-library:regenerate --with-responsive-images` leaves behind:
    // a second candidate set built off the original, so neither capped nor in
    // the configured format — and unreachable, because the resolver reads the
    // conversion's srcset and only falls back when that one is empty.
    $rendered = 'pic___responsive_1920_1440.webp';
    $originalLevel = 'pic___media_library_original_4032_3024.jpg';

    Storage::disk('public')->put($directories['responsive'].$rendered, 'keep');
    Storage::disk('public')->put($directories['responsive'].$originalLevel, 'drop');

    $media->responsive_images = [
        'responsive' => ['urls' => [$rendered]],
        'media_library_original' => ['urls' => [$originalLevel]],
    ];
    $media->save();

    $this->artisan('cms:media:prune --force')
        ->expectsOutputToContain('uncapped original-level srcset')
        ->assertSuccessful();

    expect(Storage::disk('public')->exists($directories['responsive'].$rendered))->toBeTrue()
        ->and(Storage::disk('public')->exists($directories['responsive'].$originalLevel))->toBeFalse();

    // The registration goes with the files, or the next run reports them again
    // and the column points at nothing.
    expect($media->fresh()->responsive_images)->toBe(['responsive' => ['urls' => [$rendered]]]);
});

it('keeps the original-level srcset when it is the only one there is', function () {
    $tenant = Tenant::factory()->create();
    $media = libraryMedia($tenant);
    $directories = mediaDirectories($media);

    // No conversion-level candidates (conversions off, or not generated yet):
    // the original-level set IS the srcset the resolver falls back to.
    $originalLevel = 'pic___media_library_original_800_600.jpg';
    Storage::disk('public')->put($directories['responsive'].$originalLevel, 'keep');

    $media->responsive_images = ['media_library_original' => ['urls' => [$originalLevel]]];
    $media->save();

    $this->artisan('cms:media:prune --force')->assertSuccessful();

    expect(Storage::disk('public')->exists($directories['responsive'].$originalLevel))->toBeTrue()
        ->and($media->fresh()->responsive_images)->toHaveKey('media_library_original');
});

it('reports a legacy upload tree instead of deleting it', function () {
    $tenant = Tenant::factory()->create();
    makeLibraryImage($tenant);

    // A WordPress year directory is numeric, so "numeric = media id" would
    // delete the very leftovers this command exists to surface. The nested
    // month directory is what tells the two layouts apart.
    Storage::disk('public')->put('2020/01/legacy.png', cmsTestPngBytes());
    Storage::disk('public')->put('2020/02/legacy-two.png', cmsTestPngBytes());

    $this->artisan('cms:media:prune --force')
        ->expectsOutputToContain('Legacy upload trees found')
        ->expectsOutputToContain('2020/')
        ->assertSuccessful();

    expect(Storage::disk('public')->exists('2020/01/legacy.png'))->toBeTrue()
        ->and(Storage::disk('public')->exists('2020/02/legacy-two.png'))->toBeTrue();
});

it('skips a media row whose original is not where the row says it is', function () {
    $tenant = Tenant::factory()->create();

    // A healthy majority, so the single broken row stays under the drift
    // threshold and the run still reaches a verdict.
    foreach (range(1, 12) as $ignored) {
        makeLibraryImage($tenant);
    }

    $broken = libraryMedia($tenant);
    $stray = $broken->getKey().'/conversions/pic-responsive.jpg';
    Storage::disk('public')->put($stray, 'old');
    Storage::disk('public')->delete($broken->getKey().'/'.$broken->file_name);

    $this->artisan('cms:media:prune --force')
        ->expectsOutputToContain('media row(s) skipped')
        ->assertSuccessful();

    // The directory may belong to a different row entirely — judging its
    // contents against this row would be guesswork.
    expect(Storage::disk('public')->exists($stray))->toBeTrue();
});

it('refuses to delete when the disk has drifted from the database', function () {
    $tenant = Tenant::factory()->create();

    // What a `storage/app/public` synced from production against a database
    // from another moment looks like: the directories exist, none of them hold
    // the file their row names.
    $stray = null;

    foreach ([makeLibraryImage($tenant), makeLibraryImage($tenant)] as $item) {
        $media = $item->getFirstMedia($item->getMediaLibraryCollectionName());
        $stray = $media->getKey().'/conversions/pic-responsive.jpg';
        Storage::disk('public')->put($stray, 'old');
        Storage::disk('public')->delete($media->getKey().'/'.$media->file_name);
    }

    $this->artisan('cms:media:prune --force')
        ->expectsOutputToContain('Refusing to prune')
        ->assertFailed();

    expect(Storage::disk('public')->exists($stray))->toBeTrue();
});

it('reports without deleting on a dry run', function () {
    $tenant = Tenant::factory()->create();
    $media = libraryMedia($tenant);
    $stale = $media->getKey().'/conversions/pic-responsive.jpg';
    Storage::disk('public')->put($stale, 'old');

    $this->artisan('cms:media:prune --dry-run')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Storage::disk('public')->exists($stale))->toBeTrue();
});

it('refuses to delete non-interactively without force', function () {
    $tenant = Tenant::factory()->create();
    $media = libraryMedia($tenant);
    $stale = $media->getKey().'/conversions/pic-responsive.jpg';
    Storage::disk('public')->put($stale, 'old');

    $this->artisan('cms:media:prune --no-interaction')->assertFailed();

    expect(Storage::disk('public')->exists($stale))->toBeTrue();
});

it('keeps the files when the confirmation is declined', function () {
    $tenant = Tenant::factory()->create();
    $media = libraryMedia($tenant);
    $stale = $media->getKey().'/conversions/pic-responsive.jpg';
    Storage::disk('public')->put($stale, 'old');

    $this->artisan('cms:media:prune')
        ->expectsConfirmation('Delete 1 file(s), freeing 3 B?', 'no')
        ->assertFailed();

    expect(Storage::disk('public')->exists($stale))->toBeTrue();
});

it('succeeds with nothing to do on a clean disk', function () {
    $tenant = Tenant::factory()->create();
    makeLibraryImage($tenant);

    $this->artisan('cms:media:prune --force')
        ->expectsOutputToContain('Nothing to prune')
        ->assertSuccessful();
});

it('fails when the media library is disabled', function () {
    Cms::disableMediaLibrary();

    $this->artisan('cms:media:prune')->assertFailed();
});
