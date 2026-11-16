<?php

namespace Mmoollllee\Cms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\LibraryVideos;
use Mmoollllee\Cms\Support\Media\VideoTranscoder;
use ProtoneMedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;
use Throwable;

/**
 * Checks an uploaded library video and, where browsers need it, re-encodes it
 * for the web and swaps it in as the item's file ({@see LibraryVideos} has
 * the policy).
 *
 * Carries the media id, not the model: by the time a worker gets to it the
 * file may have been replaced or deleted, and that is a reason to stop, not to
 * fail — before the encode and again after it, which can take minutes. The
 * swap goes through the item's single-file collection, so the item id the
 * pages store stays put; the old file and its stills go with the replaced
 * media row, and the new one gets its own stills queued. A file the editor
 * put in meanwhile would go the same way, so the swap only happens while the
 * converted file is still the item's newest — and never while the editor's
 * replacement is being written ({@see LibraryVideos::exclusively()}).
 *
 * Dispatched only once the upload's transaction commits — the plugin writes
 * the item and its media in one, and a worker on another connection would
 * otherwise look for a row that is not there yet.
 */
class ConvertLibraryVideo implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(
        public int $mediaId,
    ) {}

    public function handle(VideoTranscoder $transcoder): void
    {
        $media = LibraryVideos::findMedia($this->mediaId);

        if ($media === null || ! LibraryVideos::awaitsConversion($media)) {
            return;
        }

        $item = LibraryVideos::itemFor($media);

        if ($item === null) {
            return;
        }

        $converted = null;

        try {
            if (! LibraryVideos::needsWebVersion($media, $transcoder)) {
                LibraryVideos::markStatus($media, LibraryVideos::PLAYABLE);

                return;
            }

            LibraryVideos::markStatus($media, LibraryVideos::PROCESSING);

            $converted = $transcoder->toWebMp4($media, LibraryVideos::quality());

            LibraryVideos::exclusively((int) $item->getKey(), function () use ($item, $media, $converted): void {
                if (! LibraryVideos::isNewest($item, $media)) {
                    return;
                }

                $item
                    // The driver configures the collection (disk, conversions) —
                    // without it the new file would land on the vendor defaults.
                    ->driver(app(Cms::mediaDriver()))
                    ->addMedia($converted)
                    ->usingName((string) $media->name)
                    ->usingFileName(LibraryVideos::convertedFileName($media))
                    ->withCustomProperties([
                        ...LibraryVideos::carriedProperties($media),
                        LibraryVideos::STATUS => LibraryVideos::CONVERTED,
                    ])
                    ->toMediaCollection($item->getMediaLibraryCollectionName());
            });
        } finally {
            if ($converted !== null && is_file($converted)) {
                @unlink($converted);
            }

            if ($converted !== null) {
                @rmdir(dirname($converted));
            }

            // laravel-ffmpeg copies a remote original to a local directory of
            // its own and never removes it; a failed encode leaves its output.
            app(TemporaryDirectories::class)->deleteAll();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Library video conversion failed', [
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
        ]);

        $media = LibraryVideos::findMedia($this->mediaId);

        if ($media !== null && LibraryVideos::awaitsConversion($media)) {
            LibraryVideos::markStatus($media, LibraryVideos::FAILED);
        }
    }
}
