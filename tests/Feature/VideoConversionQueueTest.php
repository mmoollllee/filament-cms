<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Workbench\App\Models\Tenant;

/*
 * A video's stills are cut by ffmpeg. The vendor renders `thumb` synchronously, inside the
 * upload request, and a PHP-FPM pool locked down with open_basedir cannot reach the ffmpeg
 * binary there — the video ends up without a panel preview. Every conversion of a video goes
 * to the queue instead, where the worker makes it; images keep their immediate thumb.
 */

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

/**
 * @return array<string, bool> conversion name => whether it is queued
 */
function queuedConversionsFor(string $sourcePath, string $mimeType): array
{
    $item = makeLibraryImage(Tenant::factory()->create(), $sourcePath);
    $media = $item->getFirstMedia($item->getMediaLibraryCollectionName());
    $media->mime_type = $mimeType;
    $media->save();

    return ConversionCollection::createForMedia($media->refresh())
        ->mapWithKeys(fn (Conversion $conversion): array => [$conversion->getName() => $conversion->shouldBeQueued()])
        ->all();
}

it('queues every conversion of a video, the thumb included', function () {
    $conversions = queuedConversionsFor('fixtures/clip.mp4', 'video/mp4');

    expect($conversions)->toHaveKey('thumb')
        ->and(array_unique(array_values($conversions)))->toBe([true]);
});

it('queues a video\'s conversions even where the app renders conversions synchronously', function () {
    config(['media-library.queue_conversions_by_default' => false]);

    expect(array_unique(array_values(queuedConversionsFor('fixtures/clip.mp4', 'video/mp4'))))->toBe([true]);
});

it('keeps the immediate thumb of an image', function () {
    $conversions = queuedConversionsFor('fixtures/pic.png', 'image/png');

    expect($conversions['thumb'])->toBeFalse()
        ->and($conversions['responsive'])->toBeTrue();
});

it('does not carry a video\'s queueing over to the next image', function () {
    queuedConversionsFor('fixtures/clip.mp4', 'video/mp4');

    expect(queuedConversionsFor('fixtures/pic.png', 'image/png')['thumb'])->toBeFalse();
});
