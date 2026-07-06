<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Support\Facades\Storage;

class VideoConversionHelper
{
    /**
     * Video extensions that always need conversion to MP4/H.264.
     *
     * @var list<string>
     */
    private const CONVERT_EXTENSIONS = ['.mov', '.avi', '.wmv'];

    /**
     * Threshold in bytes above which an existing .mp4 is re-compressed.
     */
    private const RECOMPRESS_THRESHOLD = 10 * 1024 * 1024; // 10 MB

    /**
     * Determine whether a media block's video needs conversion.
     *
     * @param  array<string, mixed>  $blockData
     */
    public static function needsConversion(array $blockData, string $disk = 'public'): bool
    {
        if (! empty($blockData['video_converted'])) {
            return false;
        }

        if (! empty($blockData['video_conversion_status'])) {
            return false;
        }

        $path = $blockData['media_path'] ?? null;

        if (blank($path) || ! is_string($path)) {
            return false;
        }

        $lower = str($path)->lower();

        // Non-MP4 video extensions always need conversion
        if ($lower->endsWith(self::CONVERT_EXTENSIONS)) {
            return true;
        }

        // Large MP4 files get re-compressed
        if ($lower->endsWith('.mp4') && Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->size($path) > self::RECOMPRESS_THRESHOLD;
        }

        return false;
    }

    /**
     * CRF value for the given quality preset.
     */
    public static function crfForQuality(string $quality): int
    {
        return match ($quality) {
            'high' => 23,
            'low' => 33,
            default => 28,
        };
    }
}
