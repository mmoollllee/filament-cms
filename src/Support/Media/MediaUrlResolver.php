<?php

namespace Mmoollllee\Cms\Support\Media;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resolves stored media references to URLs and metadata.
 *
 * A reference is whatever a form field left in the data: a media-library item
 * id (int/numeric string — the saved MediaPicker state), a MediaPicker key hash
 * (its UNSAVED state, see {@see itemIdFromPickerKey()}), a legacy storage path
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
    /** Prefix of the FileData key the media-library picker uses for items. */
    private const PICKER_ITEM_KEY_PREFIX = 'media-library-item:';

    /** @var array<int, MediaLibraryItem|null> */
    private static array $items = [];

    /** @var array<int, Media|null> getItem() re-filters the media relation per call — memoized. */
    private static array $media = [];

    /** @var array<string, int|null> Picker key hash → item id; null marks "not a hash". */
    private static array $pickerKeys = [];

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
     * URL of one specific conversion, or null when that conversion does not
     * exist — no original as a consolation prize.
     *
     * {@see url()} deliberately falls back to the original so an image still shows
     * while its conversions sit in the queue. Callers that need the DERIVATIVE
     * specifically cannot use that: a video's `thumb` serving as a still is the
     * motivating case, where the fallback would hand the video file itself to an
     * `<img src>`. Legacy paths and URLs have no conversions at all and are null
     * here too, since media() only resolves item ids.
     */
    public static function conversionUrl(mixed $ref, string $conversion): ?string
    {
        $media = static::media($ref);

        return $media?->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : null;
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
        self::$pickerKeys = [];
    }

    /**
     * Normalize a stored ref: FileUpload array-state → first element,
     * blank → null, numeric → int, MediaPicker key hash → item id,
     * everything else → the string as stored.
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

        return static::itemIdFromPickerKey($ref) ?? $ref;
    }

    /**
     * The item id a MediaPicker key hash stands for, or null when the string is
     * not one (legacy path, URL, anything else).
     *
     * Filament renders builder block previews from the RAW form state —
     * `Builder::renderPreview($item->getRawState())` — and a MediaPicker does not
     * hold the item id there. It holds the picker's FileData key, encrypted:
     * `base64(AES-256-CBC("media-library-item:<id>"))` with a fixed IV, which only
     * collapses back to the id when the form is saved. Every ref shape reaching
     * this class goes through normalize(), so resolving the hash here fixes the
     * previews of all blocks at once — without it the hash falls through to the
     * legacy-path branch and each preview renders `/storage/<hash>`.
     *
     * Cheap rejects come first: the hash is base64 over a binary ciphertext and so
     * never contains a dot, whereas legacy paths always carry a file extension and
     * URLs a host. Note that `/` and `+` ARE part of the base64 alphabet, so only
     * the dot and an explicit scheme/root prefix may be used to rule a string out.
     *
     * The cipher is deterministic (fixed IV), which makes hash → id memoizable for
     * the request; negative results are cached too, so a non-hash string is only
     * ever decrypted once.
     */
    private static function itemIdFromPickerKey(string $ref): ?int
    {
        // installed(), not enabled(): the gate is only about FileData being
        // autoloadable. An install that opted out via Cms::disableMediaLibrary()
        // has no picker and therefore no hashes to decode, and should one turn up
        // anyway, decoding it to an id that item() then refuses still beats
        // handing the ciphertext to the legacy-path branch as a 404 URL.
        if (! MediaLibrary::installed()) {
            return null;
        }

        if (Str::contains($ref, '.') || Str::startsWith($ref, ['http://', 'https://', '/'])) {
            return null;
        }

        if (! array_key_exists($ref, self::$pickerKeys)) {
            // Tolerates non-base64 and undecryptable input by returning it unchanged.
            $key = FileData::ensureDecryptedKeyHash($ref);

            self::$pickerKeys[$ref] = Str::startsWith($key, self::PICKER_ITEM_KEY_PREFIX)
                ? (int) Str::after($key, self::PICKER_ITEM_KEY_PREFIX)
                : null;
        }

        return self::$pickerKeys[$ref];
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
