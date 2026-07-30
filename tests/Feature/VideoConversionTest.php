<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Jobs\ConvertVideoForWeb;
use Mmoollllee\Cms\Support\Content\VideoConversionHelper;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * Which uploads get re-encoded, and when the ConvertsUploadedVideos observer
 * dispatches the job for them.
 *
 * The size branch used to be unreachable from a test: the threshold was a private
 * const at 10 MB, so exercising it meant fabricating a file that large. Both
 * consuming apps did exactly that, holding 11 MB of heap in a suite running under
 * a 128M limit. It is config-driven now, so every test here works with fixtures of
 * a few bytes.
 */

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

it('always converts containers that are not MP4, whatever their size', function () {
    // No file on disk at all: the extension decides on its own.
    expect(VideoConversionHelper::needsConversion(['media_path' => 'clips/a.mov']))->toBeTrue()
        ->and(VideoConversionHelper::needsConversion(['media_path' => 'clips/a.avi']))->toBeTrue()
        ->and(VideoConversionHelper::needsConversion(['media_path' => 'clips/a.wmv']))->toBeTrue()
        ->and(VideoConversionHelper::needsConversion(['media_path' => 'clips/A.MOV']))->toBeTrue();
});

it('leaves web-ready containers alone', function () {
    expect(VideoConversionHelper::needsConversion(['media_path' => 'clips/a.webm']))->toBeFalse()
        ->and(VideoConversionHelper::needsConversion(['media_path' => 'photos/a.jpg']))->toBeFalse();
});

it('does not reconvert what has already been through the pipeline', function () {
    expect(VideoConversionHelper::needsConversion([
        'media_path' => 'clips/a.mov',
        'video_converted' => true,
    ]))->toBeFalse();

    // A status means a run is in flight or has ended — either way, hands off.
    expect(VideoConversionHelper::needsConversion([
        'media_path' => 'clips/a.mov',
        'video_conversion_status' => 'processing',
    ]))->toBeFalse();
});

it('treats a missing or malformed path as nothing to do', function () {
    expect(VideoConversionHelper::needsConversion([]))->toBeFalse()
        ->and(VideoConversionHelper::needsConversion(['media_path' => null]))->toBeFalse()
        ->and(VideoConversionHelper::needsConversion(['media_path' => '']))->toBeFalse()
        ->and(VideoConversionHelper::needsConversion(['media_path' => 42]))->toBeFalse();
});

it('ignores an MP4 that is not on the disk', function () {
    // Nothing to measure, so no conversion — not an exception.
    expect(VideoConversionHelper::needsConversion(['media_path' => 'clips/absent.mp4']))->toBeFalse();
});

it('re-encodes an MP4 only once it exceeds the threshold', function () {
    config(['cms.video.recompress_threshold' => 100]);

    Storage::disk('public')->put('clips/small.mp4', str_repeat('x', 50));
    Storage::disk('public')->put('clips/exact.mp4', str_repeat('x', 100));
    Storage::disk('public')->put('clips/big.mp4', str_repeat('x', 101));

    expect(VideoConversionHelper::needsConversion(['media_path' => 'clips/small.mp4']))->toBeFalse()
        // Strictly greater than: a file exactly at the threshold stays as it is.
        ->and(VideoConversionHelper::needsConversion(['media_path' => 'clips/exact.mp4']))->toBeFalse()
        ->and(VideoConversionHelper::needsConversion(['media_path' => 'clips/big.mp4']))->toBeTrue();
});

it('falls back to the documented default when the config key is gone', function () {
    config(['cms.video' => null]);

    expect(VideoConversionHelper::recompressThreshold())
        ->toBe(VideoConversionHelper::DEFAULT_RECOMPRESS_THRESHOLD)
        ->toBe(10 * 1024 * 1024);
});

it('reads the threshold from config', function () {
    config(['cms.video.recompress_threshold' => 2048]);

    expect(VideoConversionHelper::recompressThreshold())->toBe(2048);
});

it('dispatches the job when a saved content carries an oversized MP4', function () {
    config(['cms.video.recompress_threshold' => 100]);

    Storage::disk('public')->put('clips/big.mp4', str_repeat('x', 101));

    $content = Content::factory()->for(Tenant::factory())->create([
        'blocks' => [[
            'type' => 'section',
            'data' => ['blocks' => [[
                'type' => 'media',
                'data' => ['media_path' => 'clips/big.mp4'],
            ]]],
        ]],
    ]);

    Queue::assertPushed(
        ConvertVideoForWeb::class,
        fn (ConvertVideoForWeb $job): bool => $job->contentId === $content->getKey()
            && $job->mediaPath === 'clips/big.mp4'
    );
});

it('stays quiet for a content whose MP4 is within the threshold', function () {
    Storage::disk('public')->put('clips/small.mp4', str_repeat('x', 50));

    Content::factory()->for(Tenant::factory())->create([
        'blocks' => [[
            'type' => 'section',
            'data' => ['blocks' => [[
                'type' => 'media',
                'data' => ['media_path' => 'clips/small.mp4'],
            ]]],
        ]],
    ]);

    Queue::assertNotPushed(ConvertVideoForWeb::class);
});

it('finds a media block that is not nested in a section', function () {
    // The observer flattens both shapes; a top-level media block must count too.
    Content::factory()->for(Tenant::factory())->create([
        'blocks' => [[
            'type' => 'media',
            'data' => ['media_path' => 'clips/top-level.mov'],
        ]],
    ]);

    Queue::assertPushed(ConvertVideoForWeb::class);
});
