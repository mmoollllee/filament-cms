<?php

namespace Mmoollllee\Cms\Support\Media;

use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Jobs\ConvertLibraryVideo;
use Mmoollllee\Cms\Support\Content\VideoConversionHelper;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Upload-time conversion of library videos: the policy (which uploads get
 * re-encoded), the status every video carries while that happens, and the
 * hand-off to {@see ConvertLibraryVideo}.
 *
 * Browsers play H.264 in MP4 everywhere; a `.mov` straight from an iPhone
 * (HEVC, often 4K) plays in Safari and nowhere else reliably. So every video
 * in another container is converted, whatever it holds. An MP4 can hold HEVC
 * or AV1 too, which the container does not tell: the worker asks ffprobe and
 * converts one whose video is not H.264, wider than the recipe keeps, or above
 * `cms.video.recompress_threshold` at a bitrate above `cms.video.max_bitrate`
 * — a phone's recording, not what this recipe makes of ordinary footage: a
 * copy of a converted video passes as it is. WebM and Ogg stay as uploaded.
 *
 * The converted file replaces the original inside the item's single-file
 * collection: the item id — what pages store — stays the same, so every page
 * shows the web version as soon as the worker is done.
 *
 * The status lives on the Spatie media row (custom property), not on the item:
 * it describes one file, travels with it, and needs no schema change on a
 * vendor table.
 */
final class LibraryVideos
{
    public const STATUS = 'cms_video';

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const FAILED = 'failed';

    public const CONVERTED = 'converted';

    /** Checked: plays in every browser as uploaded. */
    public const PLAYABLE = 'playable';

    /** Fallback for `cms.video.max_bitrate`, in kbit/s. */
    public const DEFAULT_MAX_BITRATE = 8000;

    /** Containers that stay as uploaded, whatever they hold. */
    private const KEPT_MIME_TYPES = ['video/webm', 'video/ogg'];

    /** How long one swap of an item's file may hold it — an upload to a remote disk included. */
    private const SWAP_SECONDS = 300;

    /** How long a swap waits for the one before it. */
    private const SWAP_WAIT = 120;

    /** @var array<int, true> items whose file this process is swapping right now */
    private static array $swapping = [];

    /** Listener for Spatie's MediaHasBeenAddedEvent: upload, replace, editor paste. */
    public static function queueConversionIfNeeded(Media $media): void
    {
        if (! self::isLibraryMedia($media) || ! self::needsCheck($media)) {
            return;
        }

        self::markStatus($media, self::PENDING);

        ConvertLibraryVideo::dispatch((int) $media->getKey());
    }

    /**
     * Listener for the media model's `creating`: a new file that arrives with
     * another file's unfinished conversion status (a copy made while the
     * original was still converting) gets a conversion of its own instead of
     * waiting for one that will never run for it.
     */
    public static function forgetCarriedStatus(Media $media): void
    {
        if (self::status($media) !== null && ! in_array(self::status($media), [self::CONVERTED, self::PLAYABLE], true) && self::isLibraryMedia($media)) {
            $media->forgetCustomProperty(self::STATUS);
        }
    }

    /**
     * Whether an upload goes to the worker: every video without a status yet
     * but WebM and Ogg — an MP4 too, whose codec only ffprobe can tell
     * ({@see needsWebVersion()}).
     */
    public static function needsCheck(Media $media): bool
    {
        $mime = (string) $media->mime_type;

        return str_starts_with($mime, 'video/')
            && self::status($media) === null
            && ! in_array($mime, self::KEPT_MIME_TYPES, true);
    }

    /**
     * Whether the worker converts the file: any container but MP4; an MP4
     * whose video is not H.264, is wider than the recipe keeps, or is big at
     * a bitrate above the cap. Should ffprobe fail, the size decides alone.
     */
    public static function needsWebVersion(Media $media, VideoTranscoder $transcoder): bool
    {
        if ($media->mime_type !== 'video/mp4') {
            return true;
        }

        $big = (int) $media->size > VideoConversionHelper::recompressThreshold();

        try {
            $probe = $transcoder->probe($media);
        } catch (Throwable $exception) {
            report($exception);

            return $big;
        }

        $codec = $probe['codec'] ?? null;
        $bitrate = $probe['bitrate'] ?? null;

        return ($codec !== null && $codec !== 'h264')
            || ($probe['width'] ?? 0) > VideoTranscoder::MAX_WIDTH
            || ($big && ($bitrate === null || $bitrate > self::maxBitrate()));
    }

    /** `cms.video.max_bitrate` in bits per second. */
    public static function maxBitrate(): int
    {
        $value = config('cms.video.max_bitrate', self::DEFAULT_MAX_BITRATE);

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return max(1, (int) $value) * 1000;
        }

        report(new InvalidArgumentException('cms.video.max_bitrate takes kbit/s as a whole number, got ['.var_export($value, true).'].'));

        return self::DEFAULT_MAX_BITRATE * 1000;
    }

    /**
     * Swap an item's file — the editor replacing it, a conversion putting its
     * result in — while no other swap of the item runs. Each swap keeps only
     * the newest file of the item's single-file collection, so two at once
     * would drop whichever finished first: the editor's replacement, say,
     * for the old video converted.
     *
     * Entered again from within, it goes ahead: a queue that runs jobs
     * inline converts inside the replacement that queued the conversion.
     *
     * @template T
     *
     * @param  Closure(): T  $swap
     * @return T
     */
    public static function exclusively(int $itemId, Closure $swap): mixed
    {
        if (isset(self::$swapping[$itemId])) {
            return $swap();
        }

        return Cache::lock('cms:media-item-swap:'.$itemId, self::SWAP_SECONDS)->block(self::SWAP_WAIT, function () use ($itemId, $swap): mixed {
            self::$swapping[$itemId] = true;

            try {
                return $swap();
            } finally {
                unset(self::$swapping[$itemId]);
            }
        });
    }

    /**
     * Whether the file is the newest of its item — what the item shows next.
     * A newer one is the editor's replacement, finished or still being
     * written; the older ones go once it is.
     */
    public static function isNewest(MediaLibraryItem $item, Media $media): bool
    {
        $newest = $item->fresh()
            ?->getMedia($item->getMediaLibraryCollectionName())
            ->sortByDesc(fn (Media $file): int => (int) $file->getKey())
            ->first();

        return $newest !== null && $newest->is($media);
    }

    /** Whether a queued conversion still has work to do (a retry included). */
    public static function awaitsConversion(Media $media): bool
    {
        return in_array(self::status($media), [self::PENDING, self::PROCESSING], true);
    }

    public static function status(?Media $media): ?string
    {
        $status = $media?->getCustomProperty(self::STATUS);

        return is_string($status) ? $status : null;
    }

    public static function markStatus(Media $media, string $status): void
    {
        $media->setCustomProperty(self::STATUS, $status);
        $media->saveQuietly();
    }

    /** Editor-facing line for the file info panel; null for anything that is not a converted/converting video, or plays as uploaded. */
    public static function statusLabel(?Media $media): ?string
    {
        return match (self::status($media)) {
            self::PENDING => 'Wird für das Web vorbereitet …',
            self::PROCESSING => 'Wird für das Web umgewandelt …',
            self::FAILED => 'Umwandlung fehlgeschlagen – das Video wird so ausgeliefert, wie es hochgeladen wurde.',
            self::CONVERTED => 'Für das Web umgewandelt (MP4)',
            default => null,
        };
    }

    public static function quality(): string
    {
        $quality = (string) config('cms.video.quality', 'medium');

        return in_array($quality, ['high', 'medium', 'low'], true) ? $quality : 'medium';
    }

    /** The original's name with the extension of what it becomes. */
    public static function convertedFileName(Media $media): string
    {
        return pathinfo((string) $media->file_name, PATHINFO_FILENAME).'.mp4';
    }

    /**
     * Custom properties a converted file takes over from its original: whatever
     * the app or the library put there, minus what describes the old file
     * itself (its conversion status, its storage directory).
     *
     * @return array<string, mixed>
     */
    public static function carriedProperties(Media $media): array
    {
        return collect($media->custom_properties ?? [])
            ->except([self::STATUS, PrivateMediaPaths::TOKEN])
            ->all();
    }

    /** Whether the media row belongs to a media-library item (and not to some other model of the app). */
    public static function isLibraryMedia(Media $media): bool
    {
        return MediaLibrary::isLibraryMedia($media);
    }

    public static function itemFor(Media $media): ?MediaLibraryItem
    {
        if (! self::isLibraryMedia($media)) {
            return null;
        }

        return Cms::mediaItemModel()::query()->whereKey($media->model_id)->first();
    }

    public static function findMedia(int $id): ?Media
    {
        /** @var class-string<Media> $model */
        $model = config('media-library.media_model', Media::class);

        return $model::query()->find($id);
    }
}
