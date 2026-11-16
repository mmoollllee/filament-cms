<?php

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Jobs\ConvertLibraryVideo;
use Mmoollllee\Cms\Support\Media\LibraryVideos;
use Mmoollllee\Cms\Support\Media\VideoTranscoder;
use RalphJSmit\Filament\Explore\Data\TemporaryFileUploadData;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver\FileData;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Workbench\App\Models\Tenant;

/*
 * Library videos are re-encoded once, on upload: every container but MP4,
 * WebM and Ogg (.mov above all — straight from a phone, it plays in Safari
 * only), and an MP4 that ffprobe finds not web-ready: not H.264, wider than
 * 1920 px, or big at a camera's bitrate. The result replaces the original
 * inside the item, so the id pages store stays valid.
 */

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();

    // No ffmpeg in the suite: the fake "encodes" by writing an MP4 header.
    app()->bind(VideoTranscoder::class, fn () => new class extends VideoTranscoder
    {
        /** @var list<array{media: int, quality: string}> */
        public static array $calls = [];

        /** What happens elsewhere while the "encode" runs. */
        public static ?Closure $meanwhile = null;

        /** What "ffprobe" finds — or how it fails. */
        public static array|Throwable $probe = [];

        public function probe(Media $media): array
        {
            if (static::$probe instanceof Throwable) {
                throw static::$probe;
            }

            return static::$probe;
        }

        public function toWebMp4(Media $media, string $quality, bool $keepAudio = true): string
        {
            static::$calls[] = ['media' => (int) $media->getKey(), 'quality' => $quality];

            if (static::$meanwhile !== null) {
                (static::$meanwhile)();
            }

            $directory = sys_get_temp_dir().'/cms-video-test-'.uniqid();
            mkdir($directory);
            file_put_contents($directory.'/web.mp4', cmsVideoBytes('mp4'));

            return $directory.'/web.mp4';
        }
    });

    app(VideoTranscoder::class)::$calls = [];
    app(VideoTranscoder::class)::$meanwhile = null;
    app(VideoTranscoder::class)::$probe = ['codec' => 'h264', 'width' => 1280, 'bitrate' => 2_000_000];
});

/** Upload an MP4 and run the check the upload queued. */
function checkedMp4(array|Throwable $probe, int $padding = 0): Media
{
    app(VideoTranscoder::class)::$probe = $probe;

    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.mp4', cmsVideoBytes('mp4', padding: $padding));

    (new ConvertLibraryVideo((int) itemMedia($item)->getKey()))->handle(app(VideoTranscoder::class));

    return itemMedia($item);
}

it('queues a .mov for conversion as soon as it is uploaded', function () {
    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.mov', cmsVideoBytes('mov'));
    $media = itemMedia($item);

    expect($media->mime_type)->toBe('video/quicktime')
        ->and(LibraryVideos::status($media))->toBe(LibraryVideos::PENDING);

    Queue::assertPushed(ConvertLibraryVideo::class, fn (ConvertLibraryVideo $job) => $job->mediaId === $media->getKey());
});

it('leaves WebM as uploaded, whatever it holds', function () {
    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.webm', cmsVideoBytes('webm'));

    expect(LibraryVideos::status(itemMedia($item)))->toBeNull();

    Queue::assertNotPushed(ConvertLibraryVideo::class);
});

it('has an MP4 checked, and leaves it as uploaded when every browser plays it', function () {
    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.mp4', cmsVideoBytes('mp4'));
    $media = itemMedia($item);

    expect(LibraryVideos::status($media))->toBe(LibraryVideos::PENDING)
        ->and(LibraryVideos::statusLabel($media))->toBe('Wird für das Web vorbereitet …');

    Queue::assertPushed(ConvertLibraryVideo::class, fn (ConvertLibraryVideo $job) => $job->mediaId === $media->getKey());

    (new ConvertLibraryVideo((int) $media->getKey()))->handle(app(VideoTranscoder::class));

    $checked = itemMedia($item);

    expect($checked->is($media))->toBeTrue()
        ->and(LibraryVideos::status($checked))->toBe(LibraryVideos::PLAYABLE)
        ->and(LibraryVideos::statusLabel($checked))->toBeNull()
        ->and(app(VideoTranscoder::class)::$calls)->toBe([]);
});

it('converts an MP4 not every browser plays, or a camera\'s big recording', function (array $probe, int $threshold) {
    config(['cms.video.recompress_threshold' => $threshold]);

    $media = checkedMp4($probe, padding: 200);

    expect(LibraryVideos::status($media))->toBe(LibraryVideos::CONVERTED)
        ->and(app(VideoTranscoder::class)::$calls)->toHaveCount(1);
})->with([
    'HEVC' => [['codec' => 'hevc', 'width' => 1920, 'bitrate' => 2_000_000], 10 * 1024 * 1024],
    'AV1' => [['codec' => 'av1', 'width' => 1280, 'bitrate' => 1_000_000], 10 * 1024 * 1024],
    'wider than 1920 px' => [['codec' => 'h264', 'width' => 3840, 'bitrate' => 2_000_000], 10 * 1024 * 1024],
    'big at a camera\'s bitrate' => [['codec' => 'h264', 'width' => 1920, 'bitrate' => 17_000_000], 100],
    'big, bitrate unknown' => [['codec' => 'h264', 'width' => 1920, 'bitrate' => null], 100],
]);

it('leaves a big MP4 at a modest bitrate as uploaded', function () {
    config(['cms.video.recompress_threshold' => 100]);

    $media = checkedMp4(['codec' => 'h264', 'width' => 1920, 'bitrate' => 4_000_000], padding: 200);

    expect(LibraryVideos::status($media))->toBe(LibraryVideos::PLAYABLE)
        ->and(app(VideoTranscoder::class)::$calls)->toBe([]);
});

it('lets the size decide alone when ffprobe fails', function () {
    Exceptions::fake();
    config(['cms.video.recompress_threshold' => 100]);

    $small = checkedMp4(new RuntimeException('ffprobe not found'));
    $big = checkedMp4(new RuntimeException('ffprobe not found'), padding: 200);

    expect(LibraryVideos::status($small))->toBe(LibraryVideos::PLAYABLE)
        ->and(LibraryVideos::status($big))->toBe(LibraryVideos::CONVERTED)
        ->and(app(VideoTranscoder::class)::$calls)->toHaveCount(1);

    Exceptions::assertReported(RuntimeException::class);
});

it('does not touch images', function () {
    makeLibraryImage(Tenant::factory()->create());

    Queue::assertNotPushed(ConvertLibraryVideo::class);
});

it('runs only after the upload transaction commits', function () {
    expect(new ConvertLibraryVideo(1))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

it('swaps the converted MP4 in as the item\'s file, keeping the item id', function () {
    config(['cms.video.quality' => 'high']);

    $item = makeLibraryFile(Tenant::factory()->create(), 'Urlaub 2026.mov', cmsVideoBytes('mov'));
    $original = itemMedia($item);
    $originalPath = $original->getPathRelativeToRoot();

    (new ConvertLibraryVideo((int) $original->getKey()))->handle(app(VideoTranscoder::class));

    $converted = itemMedia($item);

    expect($converted->getKey())->not->toBe($original->getKey())
        // Spatie sanitizes the name on the way in; the extension is what changes.
        ->and($converted->file_name)->toBe('Urlaub-2026.mp4')
        ->and($converted->name)->toBe('Urlaub 2026')
        ->and($converted->mime_type)->toBe('video/mp4')
        ->and(LibraryVideos::status($converted))->toBe(LibraryVideos::CONVERTED)
        ->and(Media::query()->whereKey($original->getKey())->exists())->toBeFalse()
        ->and($item->getMedia($item->getMediaLibraryCollectionName()))->toHaveCount(1)
        ->and(app(VideoTranscoder::class)::$calls)->toBe([['media' => (int) $original->getKey(), 'quality' => 'high']]);

    Storage::disk('public')->assertMissing($originalPath);
    Storage::disk('public')->assertExists($converted->getPathRelativeToRoot());

    // The converted file is not sent round again.
    Queue::assertPushed(ConvertLibraryVideo::class, 1);
});

it('stops quietly when the file was replaced or deleted before the worker got to it', function () {
    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.mov', cmsVideoBytes('mov'));
    $mediaId = (int) itemMedia($item)->getKey();

    $item->delete();

    (new ConvertLibraryVideo($mediaId))->handle(app(VideoTranscoder::class));

    expect(app(VideoTranscoder::class)::$calls)->toBe([]);
});

it('marks the video as failed when the worker gives up', function () {
    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.mov', cmsVideoBytes('mov'));
    $media = itemMedia($item);

    (new ConvertLibraryVideo((int) $media->getKey()))->failed(new RuntimeException('ffmpeg exited with 1'));

    expect(LibraryVideos::status($media->refresh()))->toBe(LibraryVideos::FAILED)
        ->and(LibraryVideos::statusLabel($media))->toContain('fehlgeschlagen');
});

it('uses the shared ffmpeg recipe for both pipelines', function () {
    $format = VideoTranscoder::format('low', keepAudio: false);

    expect($format->getAdditionalParameters())->toContain('-crf', '33', '+faststart', '-an');
});

it('reads codec and width with the real ffprobe, and converts an MP4 that is not H.264', function () {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));

    if ($ffmpeg === '') {
        $this->markTestSkipped('ffmpeg is not installed.');
    }

    $source = sys_get_temp_dir().'/cms-real-'.uniqid().'.mp4';
    exec(escapeshellarg($ffmpeg).' -v error -f lavfi -i testsrc=duration=1:size=64x48:rate=10 -c:v mpeg4 '.escapeshellarg($source), result_code: $exit);
    expect($exit)->toBe(0);

    $item = makeLibraryFile(Tenant::factory()->create(), 'real.mp4', (string) file_get_contents($source));
    @unlink($source);

    app()->forgetInstance(VideoTranscoder::class);
    app()->bind(VideoTranscoder::class, VideoTranscoder::class);
    $transcoder = app(VideoTranscoder::class);

    expect($transcoder->probe(itemMedia($item)))->toMatchArray(['codec' => 'mpeg4', 'width' => 64])
        ->and($transcoder->probe(itemMedia($item))['bitrate'])->toBeGreaterThan(0);

    (new ConvertLibraryVideo((int) itemMedia($item)->getKey()))->handle($transcoder);

    $converted = itemMedia($item);

    expect(LibraryVideos::status($converted))->toBe(LibraryVideos::CONVERTED)
        ->and($transcoder->probe($converted))->toMatchArray(['codec' => 'h264', 'width' => 64]);
});

it('turns a real .mov into a playable MP4 with ffmpeg', function () {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));

    if ($ffmpeg === '') {
        $this->markTestSkipped('ffmpeg is not installed.');
    }

    $source = sys_get_temp_dir().'/cms-real-'.uniqid().'.mov';
    exec(escapeshellarg($ffmpeg).' -v error -f lavfi -i testsrc=duration=1:size=64x48:rate=10 -c:v mpeg4 '.escapeshellarg($source), result_code: $exit);
    expect($exit)->toBe(0);

    $item = makeLibraryFile(Tenant::factory()->create(), 'real.mov', (string) file_get_contents($source));
    @unlink($source);

    app()->forgetInstance(VideoTranscoder::class);
    app()->bind(VideoTranscoder::class, VideoTranscoder::class);

    (new ConvertLibraryVideo((int) itemMedia($item)->getKey()))->handle(app(VideoTranscoder::class));

    $converted = itemMedia($item);

    expect($converted->mime_type)->toBe('video/mp4')
        ->and($converted->file_name)->toBe('real.mp4')
        ->and(LibraryVideos::status($converted))->toBe(LibraryVideos::CONVERTED)
        ->and(Storage::disk('public')->size($converted->getPathRelativeToRoot()))->toBeGreaterThan(0);
});

it('drops its result when the editor replaced the file while it encoded', function () {
    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.mov', cmsVideoBytes('mov'));
    $original = itemMedia($item);

    app(VideoTranscoder::class)::$meanwhile = function () use ($item): void {
        Storage::disk('public')->put('incoming/final.webm', cmsVideoBytes('webm'));

        $item->driver(app(Cms::mediaDriver()))
            ->addMediaFromDisk('incoming/final.webm', 'public')
            ->toMediaCollection($item->getMediaLibraryCollectionName());
    };

    (new ConvertLibraryVideo((int) $original->getKey()))->handle(app(VideoTranscoder::class));

    expect(itemMedia($item)->file_name)->toBe('final.webm')
        ->and($item->refresh()->getMedia($item->getMediaLibraryCollectionName()))->toHaveCount(1);
});

it('keeps the editor\'s replacement that is still being written when the encode finishes', function () {
    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.mov', cmsVideoBytes('mov'));
    $original = itemMedia($item);

    // The replacement's row is there; Spatie has yet to remove the older file.
    app(VideoTranscoder::class)::$meanwhile = function () use ($item): void {
        Storage::disk('public')->put('incoming/final.webm', cmsVideoBytes('webm'));

        $replacement = $item->driver(app(Cms::mediaDriver()))
            ->addMediaFromDisk('incoming/final.webm', 'public')
            ->toMediaCollection('incoming');

        Media::query()->whereKey($replacement->getKey())->update(['collection_name' => $item->getMediaLibraryCollectionName()]);
    };

    (new ConvertLibraryVideo((int) $original->getKey()))->handle(app(VideoTranscoder::class));

    expect($item->refresh()->getMedia($item->getMediaLibraryCollectionName())->pluck('file_name')->all())
        ->toContain('final.webm')
        ->not->toContain('clip.mp4');
});

it('converts a replacement while the queue runs its jobs inline, holding the item meanwhile', function () {
    // Stills of a video need ffmpeg; the conversion is what counts here.
    config(['queue.default' => 'sync', 'media-library.image_generators' => [Image::class]]);
    Queue::except(ConvertLibraryVideo::class);

    $item = makeLibraryFile(Tenant::factory()->create(), 'alt.webm', cmsVideoBytes('webm'));
    $driver = app(Cms::mediaDriver());
    Storage::disk('public')->put('incoming/neu.mov', cmsVideoBytes('mov'));

    $held = null;
    app(VideoTranscoder::class)::$meanwhile = function () use ($item, &$held): void {
        $held = ! Cache::lock('cms:media-item-swap:'.$item->getKey(), 1)->get();
    };

    // Inside the replacement, before Spatie removed the old file.
    $driver->replaceFile(
        FileData::fromMediaLibraryItem($item->driver($driver), $driver->getImageGenerators()),
        TemporaryFileUploadData::fromDisk('public', 'incoming/neu.mov', originalFilename: 'neu.mov'),
    );

    $files = $item->refresh()->getMedia($item->getMediaLibraryCollectionName());

    expect($held)->toBeTrue()
        ->and($files)->toHaveCount(1)
        ->and($files->first()->file_name)->toBe('neu.mp4')
        ->and(LibraryVideos::status($files->first()))->toBe(LibraryVideos::CONVERTED);
});

it('leaves nothing behind when the item was deleted while it encoded', function () {
    $item = makeLibraryFile(Tenant::factory()->create(), 'clip.mov', cmsVideoBytes('mov'));
    $original = itemMedia($item);

    app(VideoTranscoder::class)::$meanwhile = fn () => $item->delete();

    (new ConvertLibraryVideo((int) $original->getKey()))->handle(app(VideoTranscoder::class));

    expect(Media::query()->where('model_id', $item->getKey())->count())->toBe(0);
});

it('does not encode a copy of a converted video a second time', function () {
    config(['cms.video.recompress_threshold' => 1]);

    $tenant = Tenant::factory()->create();
    $item = makeLibraryFile($tenant, 'clip.mov', cmsVideoBytes('mov'));
    (new ConvertLibraryVideo((int) itemMedia($item)->getKey()))->handle(app(VideoTranscoder::class));
    $converted = itemMedia($item);

    // What Duplizieren does: the same file into a new item, without its properties.
    $copyItem = Cms::mediaItemModel()::query()->create(['tenant_type' => $tenant->getMorphClass(), 'tenant_id' => $tenant->getKey()]);
    $copyItem->driver(app(Cms::mediaDriver()))
        ->addMediaFromDisk($converted->getPathRelativeToRoot(), $converted->disk)
        ->preservingOriginal()
        ->toMediaCollection($copyItem->getMediaLibraryCollectionName());

    // ffprobe finds what the recipe made: H.264, 1920 px at most, a modest bitrate.
    (new ConvertLibraryVideo((int) itemMedia($copyItem)->getKey()))->handle(app(VideoTranscoder::class));

    expect(LibraryVideos::status(itemMedia($copyItem)))->toBe(LibraryVideos::PLAYABLE)
        ->and(app(VideoTranscoder::class)::$calls)->toHaveCount(1);
});
