<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\CmsMediaLibraryItemImageGenerator;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use RalphJSmit\Filament\MediaLibrary\ImageGenerators\MediaLibraryItemImageGenerator;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Workbench\App\Models\Tenant;

/*
 * The picker decides between a thumbnail and a file-type icon by asking the
 * image generator whether it supports the file. The vendor generator answers
 * yes for anything whose `generated_conversions` carries a true — a column that
 * records that a conversion RAN, not that it wrote a file. The library
 * registers `responsive`/`800`/`400`/`thumb` for every item, videos included,
 * so an uploaded MP4 is flagged for derivatives it never gets and the tile
 * renders an <img> at a URL that 404s.
 */

beforeEach(function () {
    Storage::fake('public');
    // Conversions would otherwise run inline; these tests set the flags they
    // assert on, exactly as the library leaves them for a video.
    Queue::fake();
});

/** The picker's FileData for a library item, as the driver hands it to the view. */
function pickerFile(MediaLibraryItem $item): MediaLibraryItemDriver\FileData
{
    $driver = app(Cms::mediaDriver());

    return MediaLibraryItemDriver\FileData::fromMediaLibraryItem(
        $item->driver($driver),
        $driver->getImageGenerators(),
    );
}

it('binds the guarded image generator over the vendor one', function () {
    expect(app(MediaLibraryItemImageGenerator::class))
        ->toBeInstanceOf(CmsMediaLibraryItemImageGenerator::class);
});

it('offers no thumbnail for a video flagged with image conversions', function () {
    $tenant = Tenant::factory()->create();

    $item = makeLibraryImage($tenant, 'fixtures/clip.mp4');
    $media = $item->getFirstMedia($item->getMediaLibraryCollectionName());

    // What the library leaves behind for a video: conversions marked as run,
    // with nothing on the disk to serve.
    $media->mime_type = 'video/mp4';
    $media->generated_conversions = ['thumb' => true, '800' => true, '400' => true];
    $media->save();

    // Without this the file would be refused on its extension alone and the
    // test would pass without ever reaching the guard.
    expect($media->getGeneratedConversions()->contains(true))->toBeTrue();

    expect(pickerFile($item->refresh())->getImageGenerator())->toBeNull();
});

it('still offers a thumbnail for an image whose conversions are pending', function () {
    $tenant = Tenant::factory()->create();

    $item = makeLibraryImage($tenant);

    // No conversion has run yet — the generator falls back to the original,
    // which an <img> can display, so the tile must keep showing a preview.
    expect(pickerFile($item)->getImageGenerator())
        ->toBeInstanceOf(CmsMediaLibraryItemImageGenerator::class);
});
