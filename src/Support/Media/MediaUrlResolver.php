<?php

namespace Mmoollllee\Cms\Support\Media;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resolves stored media references to URLs and metadata.
 *
 * A reference is whatever a form field left in the data: a media-library item
 * id (int/numeric string — the MediaPicker state), a legacy storage path
 * (pre-media-library uploads), an absolute URL, or the FileUpload array-state
 * quirk. Numeric refs resolve through the Spatie Media API — never hand-built
 * disk URLs — so app-level UrlGenerator swaps (private-disk serve routes à la
 * nest) apply everywhere automatically.
 *
 * Lookups are request-cached; {@see preload()} batches a whole content's refs
 * into one query before block rendering.
 */
final class MediaUrlResolver
{
    /** @var array<int, MediaLibraryItem|null> */
    private static array $items = [];

    /** @var array<int, Media|null> getItem() re-filters the media relation per call — memoized. */
    private static array $media = [];

    public static function url(mixed $ref, ?string $conversion = null): ?string
    {
        $ref = static::normalize($ref);

        if ($ref === null) {
            return null;
        }

        if (! is_int($ref)) {
            if (Str::startsWith($ref, ['http://', 'https://', '/'])) {
                return $ref;
            }

            return '/storage/'.$ref;
        }

        $media = static::media($ref);

        if ($media === null) {
            return null;
        }

        if ($conversion !== null && $media->hasGeneratedConversion($conversion)) {
            return $media->getUrl($conversion);
        }

        return $media->getUrl();
    }

    /**
     * Absolute variant of {@see url()} — social crawlers and mail clients
     * have no base URL to resolve relative paths against. Protocol-relative
     * URLs (`//host/…`) count as absolute.
     */
    public static function absoluteUrl(mixed $ref, ?string $conversion = null): ?string
    {
        $url = static::url($ref, $conversion);

        if ($url === null) {
            return null;
        }

        return Str::startsWith($url, ['http://', 'https://', '//']) ? $url : url($url);
    }

    /**
     * The responsive-images srcset for an image ref. Null for legacy paths,
     * non-images, pending conversions, and disks without a public base URL
     * (private-disk installs serve per-request — srcset degrades to a plain
     * conversion URL there).
     */
    public static function srcset(mixed $ref): ?string
    {
        $media = static::media($ref);

        if ($media === null || ! static::isImageMime($media->mime_type)) {
            return null;
        }

        if (blank(config("filesystems.disks.{$media->disk}.url"))) {
            return null;
        }

        // The plugin registers responsive images on the `responsive`
        // conversion; fall back to original-level responsive images for
        // custom conversion sets.
        $srcset = $media->getSrcset('responsive');

        if (blank($srcset)) {
            $srcset = $media->getSrcset();
        }

        return filled($srcset) ? $srcset : null;
    }

    /** Central alt text stored on the media-library item (null for legacy refs). */
    public static function alt(mixed $ref): ?string
    {
        return static::item($ref)?->alt_text;
    }

    public static function mime(mixed $ref): ?string
    {
        return static::media($ref)?->mime_type;
    }

    /**
     * Whether the ref points at a video — by item MIME for library refs, by
     * file extension for legacy paths (the pre-library heuristic).
     */
    public static function isVideo(mixed $ref): bool
    {
        $ref = static::normalize($ref);

        if ($ref === null) {
            return false;
        }

        if (is_int($ref)) {
            return Str::startsWith((string) static::mime($ref), 'video/');
        }

        // Same extension set <x-site.media-item> detects (ogg included).
        return Str::of($ref)->lower()->endsWith(['.mp4', '.webm', '.mov', '.ogg']);
    }

    /** Whether the ref is a media-library item id (vs. a legacy path/URL). */
    public static function isLibraryRef(mixed $ref): bool
    {
        return is_int(static::normalize($ref));
    }

    public static function item(mixed $ref): ?MediaLibraryItem
    {
        $ref = static::normalize($ref);

        if (! is_int($ref) || ! MediaLibrary::enabled()) {
            return null;
        }

        if (! array_key_exists($ref, self::$items)) {
            self::$items[$ref] = Cms::mediaItemModel()::query()->with('media')->find($ref);
        }

        return self::$items[$ref];
    }

    public static function media(mixed $ref): ?Media
    {
        $ref = static::normalize($ref);

        if (! is_int($ref)) {
            return null;
        }

        if (! array_key_exists($ref, self::$media)) {
            self::$media[$ref] = static::item($ref)?->getItem();
        }

        return self::$media[$ref];
    }

    /**
     * Batch-load every library ref found in the given values (nested arrays
     * are scanned) — call once per content before rendering its blocks to
     * avoid per-image queries.
     *
     * @param  iterable<mixed>  $values
     */
    public static function preload(iterable $values): void
    {
        if (! MediaLibrary::enabled()) {
            return;
        }

        $ids = [];

        $collect = function (mixed $value) use (&$collect, &$ids): void {
            if (is_iterable($value)) {
                foreach ($value as $nested) {
                    $collect($nested);
                }

                return;
            }

            $ref = static::normalize($value);

            if (is_int($ref) && ! array_key_exists($ref, self::$items)) {
                $ids[$ref] = true;
            }
        };

        $collect($values);

        if ($ids === []) {
            return;
        }

        $found = Cms::mediaItemModel()::query()
            ->with('media')
            ->findMany(array_keys($ids))
            ->keyBy(fn ($item): int => (int) $item->getKey());

        foreach (array_keys($ids) as $id) {
            self::$items[$id] = $found->get($id);
        }
    }

    /** Reset the request caches (request teardown + tests). */
    public static function flush(): void
    {
        self::$items = [];
        self::$media = [];
    }

    /**
     * Normalize a stored ref: FileUpload array-state → first element,
     * blank → null, numeric → int, everything else → trimmed string.
     */
    public static function normalize(mixed $ref): int|string|null
    {
        if (is_array($ref)) {
            $ref = Arr::first($ref);
        }

        if ($ref instanceof MediaLibraryItem) {
            return (int) $ref->getKey();
        }

        if (is_int($ref)) {
            return $ref;
        }

        if (! is_string($ref) || blank($ref)) {
            return null;
        }

        if (ctype_digit($ref)) {
            return (int) $ref;
        }

        return $ref;
    }

    /**
     * Whether the MIME type is a raster image the GD/Imagick pipeline can
     * process — SVG and ICO are image/* but not rasterizable, so neither
     * srcset generation nor conversions (og crop) may run on them.
     */
    public static function isProcessableImageMime(?string $mime): bool
    {
        return $mime !== null
            && Str::startsWith($mime, 'image/')
            && ! in_array($mime, ['image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'], true);
    }

    protected static function isImageMime(?string $mime): bool
    {
        return static::isProcessableImageMime($mime);
    }
}
