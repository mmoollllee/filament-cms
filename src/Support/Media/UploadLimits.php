<?php

namespace Mmoollllee\Cms\Support\Media;

use InvalidArgumentException;

/**
 * The upload ceiling the CMS sets as its default: 128 MB, room for a phone
 * video.
 *
 * Two vendor limits sit in front of every upload, and both are far lower out
 * of the box: the media library refuses anything over 10 MB (Spatie
 * `max_file_size`, which the picker also shows as its limit), and Livewire
 * validates every temporary upload against 12 MB before a field even sees it.
 * A video from a phone fails both.
 *
 * The CMS only raises a value the app has not decided on: Spatie's limit while
 * it still holds the vendor default, Livewire's rules while they are unset. An
 * app that configured either keeps its own — a value equal to the vendor
 * default reads as undecided, so an app that wants exactly 10 MB says so
 * through the one knob that covers both: `cms.media.max_upload_size`.
 *
 * Not reachable from here: PHP's `upload_max_filesize`/`post_max_size` and the
 * web server's body limit (nginx `client_max_body_size`). They have to allow
 * the same size on every server.
 */
final class UploadLimits
{
    public const DEFAULT_MAX_SIZE = 128 * 1024 * 1024;

    /** Spatie's shipped `max_file_size` — the value that means "not decided by the app". */
    public const SPATIE_DEFAULT_MAX_SIZE = 10 * 1024 * 1024;

    /**
     * Livewire signs the upload URL for this many minutes and checks the
     * signature once the request has arrived — that is, after the whole body
     * was transferred. Its default of 5 minutes fails a 128 MB upload on any
     * uplink slower than roughly 3.5 Mbit/s, with a bare 401.
     */
    public const UPLOAD_MINUTES = 30;

    public const LIVEWIRE_DEFAULT_UPLOAD_MINUTES = 5;

    public static function maxSize(): int
    {
        $value = config('cms.media.max_upload_size', self::DEFAULT_MAX_SIZE);

        try {
            return max(1, self::bytes($value));
        } catch (InvalidArgumentException $exception) {
            // A typo in the env must not take the site down; it is reported,
            // and uploads keep the default meanwhile.
            report($exception);

            return self::DEFAULT_MAX_SIZE;
        }
    }

    /**
     * A size in bytes — as a number, or the way php.ini writes it (`256M`,
     * `1G`), since the limits next to it are written that way.
     *
     * @throws InvalidArgumentException for anything else: `(int) '256M'` would be 256 bytes, `128MB` would be 128
     */
    public static function bytes(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            if (ctype_digit($value)) {
                return (int) $value;
            }

            if (preg_match('/^\d+[KMG]$/i', $value) === 1) {
                return ini_parse_quantity($value);
            }
        }

        throw new InvalidArgumentException('Expected a size in bytes or like 256M, got ['.(is_scalar($value) ? $value : get_debug_type($value)).'].');
    }

    public static function maxSizeKb(): int
    {
        return (int) ceil(self::maxSize() / 1024);
    }

    public static function apply(): void
    {
        if (config('media-library.max_file_size') === self::SPATIE_DEFAULT_MAX_SIZE) {
            config(['media-library.max_file_size' => self::maxSize()]);
        }

        if (config('livewire.temporary_file_upload.rules') === null) {
            config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.self::maxSizeKb()]]);
        }

        $minutes = config('livewire.temporary_file_upload.max_upload_time');

        // Only the vendor's own value is undecided: an app's '5' from its env
        // is a choice.
        if ($minutes === null || $minutes === self::LIVEWIRE_DEFAULT_UPLOAD_MINUTES) {
            config(['livewire.temporary_file_upload.max_upload_time' => self::UPLOAD_MINUTES]);
        }
    }
}
