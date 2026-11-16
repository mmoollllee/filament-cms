<?php

namespace Mmoollllee\Cms\Support\Media\References;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Finds media-library item ids in stored content: blocks, payload, meta,
 * draft stashes, tenant settings.
 *
 * A saved MediaPicker holds a bare integer, which by itself says nothing — a
 * block's `columns: 3` is an integer too. So ids are only taken from keys
 * NAMED like a media field ({@see MEDIA_KEY_PATTERN}: `image_path`, `gallery`,
 * `team_photo`, … — the CMS's own fields all are) and from whatever an app
 * registers ({@see Cms::registerMediaReferenceKeys()}): an app block whose key
 * nobody registered is still protected from having its picture deleted. A
 * list under such a key is a multiple picker; each element counts. Values
 * that are not ids (paths, URLs, icon names) never count, whatever the key.
 *
 * A block switched off in its options ("Aktiv") is skipped by the frontend,
 * everything inside it too: what it references is still used — the block
 * may be switched on again — but not shown ({@see hidden()}).
 *
 * Rich text needs no key: an image node carries its item in `data-id`, in
 * stored HTML and in TipTap JSON alike, as a bare id or as the picker's
 * encrypted key (optionally `|conversion`-suffixed), which
 * {@see MediaUrlResolver::normalize()} decodes. Other nodes use `data-id` too
 * (custom blocks, merge tags, mentions) — only image nodes are read.
 *
 * A file can also be used by its URL — a link to a PDF in the text, a URL
 * pasted into a button: anywhere a string holds `…/storage/{media-id}/…` (or
 * the private `{media-id}-{token}/`), the media's item counts as referenced.
 */
final class MediaReferenceScanner
{
    /**
     * Keys that read as a media field by their name — the CMS's own fields
     * (block media and poster, section backgrounds, the page header's images,
     * og:image, the tenant's logos) and an app's alike: the key ENDS in a word
     * for a picture, a video or a document, optionally followed by `_id` or
     * `_ids`. English words on their own or after an underscore (`image`,
     * `hero_image`, `file_path`, `product_datasheet`, `image_id`) — `profile`
     * ends in "file" and is none. German words also as the last part of a
     * compound, the way German keys are written (`galerie`, `titelbild`,
     * `masszeichnung`, `datenblatt`, `firmenlogo`, `pdf_datei`). camelCase
     * keys are read as snake_case (`heroImage`). Deliberately no
     * `_size`/`_width`/`_quality` — those hold numbers that are not ids — and
     * nothing that starts as a flag or a count (`is_video`, `show_image`,
     * `zeige_bild`, `max_files`, `anzahl_bilder`): a "1" there is not item 1.
     *
     * A key the pattern misses makes a used file look unused — deletable.
     * Matching too much only protects a file that could go. So the lists err
     * on the side of documents.
     */
    public const MEDIA_KEY_PATTERN = '/^(?!(?:is|has|show|hide|use|with|enable|disable'
        .'|max|min|num|count|limit|total|anzahl|ist|hat|zeige|mit|ohne|kein|nur)_)(?:'
        .'.*(?:^|_)(?:image|images|picture|pictures|photo|photos|poster|thumbnail|gallery|media|path'
        .'|file|files|document|documents|download|downloads|attachment|attachments|pdf|pdfs'
        .'|datasheet|datasheets|brochure|brochures|catalog|catalogs|catalogue|catalogues|certificate|certificates)'
        .'|.*(?:bild|bilder|foto|fotos|grafik|grafiken|galerie|logo|logos|video|videos|zeichnung|zeichnungen'
        .'|datei|dateien|dokument|dokumente|anhang|anhaenge|blatt|blaetter'
        .'|prospekt|prospekte|broschuere|broschueren|katalog|kataloge|zertifikat|zertifikate)'
        .')(?:_ids?)?$/';

    /** @var array<string, bool> */
    private array $keys;

    /** Captures the media id of a library file's URL; null when the disk has no URL. */
    private ?string $urlPattern = null;

    /** What a string must contain for the URL pattern to be worth running. */
    private ?string $urlNeedle = null;

    /** @var array{0: array<int, true>, 1: array<int, true>} item ids met in the current scan: shown, in switched-off blocks */
    private array $found = [[], []];

    /** @var array{0: array<int, true>, 1: array<int, true>} media ids met by URL in the current scan, the same way */
    private array $foundMedia = [[], []];

    /** @var array<int, int> item ids the last scan met only in switched-off blocks */
    private array $hiddenIds = [];

    /** @var array<int, int|null> media id => its library item, or null when it is not a library file */
    private array $itemOfMedia = [];

    /**
     * @param  iterable<string>  $keys
     * @param  string|null  $libraryUrl  where the library disk serves its files, e.g. `http://localhost/storage`
     */
    public function __construct(iterable $keys, ?string $libraryUrl = null)
    {
        $this->keys = [];

        foreach ($keys as $key) {
            $this->keys[$key] = true;
        }

        if (filled($libraryUrl)) {
            $this->urlNeedle = self::linkRoot($libraryUrl);
            $this->urlPattern = '#'.preg_quote((string) self::linkBase($libraryUrl), '#')
                .'(\d+)(?:-[A-Za-z0-9]{16,64})?/[^\s"\'<>?\#]+#';
        }
    }

    public static function make(): self
    {
        return new self(Cms::mediaReferenceKeys(), self::libraryUrl());
    }

    /**
     * What comes right before `{media-id}/` in a link to a library file: the
     * disk's URL path (`/storage/`) and the media-library prefix, if any.
     * Null for a disk without public URLs.
     */
    public static function linkBase(?string $libraryUrl = null): ?string
    {
        $libraryUrl ??= self::libraryUrl();

        if (blank($libraryUrl)) {
            return null;
        }

        $prefix = trim((string) config('media-library.prefix', ''), '/');

        return self::linkRoot($libraryUrl).($prefix !== '' ? $prefix.'/' : '');
    }

    /**
     * The path alone also matches relative links (`/storage/12/a.pdf`) and the
     * site's other hosts; a disk served from a host's root (a bucket) has none,
     * and only its full URL is safe to look for.
     */
    private static function linkRoot(string $libraryUrl): string
    {
        $path = rtrim((string) parse_url($libraryUrl, PHP_URL_PATH), '/');

        return ($path !== '' ? $path : rtrim($libraryUrl, '/')).'/';
    }

    /**
     * @return array<int, int> the item ids the value shows — referenced anywhere outside a switched-off block
     */
    public function scan(mixed $value): array
    {
        $this->found = [[], []];
        $this->foundMedia = [[], []];

        $this->walk($value, null, false);

        foreach ([0, 1] as $off) {
            foreach ($this->itemsOfMedia(array_keys($this->foundMedia[$off])) as $itemId) {
                $this->found[$off][$itemId] = true;
            }
        }

        $this->hiddenIds = array_keys(array_diff_key($this->found[1], $this->found[0]));

        return array_keys($this->found[0]);
    }

    /**
     * @return array<int, int> the item ids the last scan met only inside switched-off blocks
     */
    public function hidden(): array
    {
        return $this->hiddenIds;
    }

    private static function libraryUrl(): ?string
    {
        try {
            return Storage::disk(Cms::mediaDisk())->url('');
        } catch (Throwable) {
            // A disk without public URLs: nothing to find by URL.
            return null;
        }
    }

    /**
     * @param  bool  $off  inside a switched-off block
     */
    private function walk(mixed $value, ?string $key, bool $off): void
    {
        if (is_array($value)) {
            // As the frontend decides it: `! ($data['active'] ?? true)`.
            $off = $off || (isset($value['type']) && is_array($value['data'] ?? null) && ! ($value['data']['active'] ?? true));

            if (($value['type'] ?? null) === 'image' && is_array($value['attrs'] ?? null)) {
                $this->collect($this->withoutConversion($value['attrs']['id'] ?? null), $off);
            }

            // A list hands its key down: every element of a multiple picker is
            // a reference in its own right.
            $isList = array_is_list($value);

            foreach ($value as $childKey => $child) {
                $this->walk($child, $isList ? $key : (string) $childKey, $off);
            }

            return;
        }

        if ($key !== null && (is_int($value) || is_string($value)) && $this->isMediaKey($key)) {
            $this->collect($value, $off);
        }

        if (is_string($value) && str_contains($value, 'data-id')) {
            $this->collectFromHtml($value, $off);
        }

        if (is_string($value) && $this->urlNeedle !== null && str_contains($value, $this->urlNeedle)) {
            $this->collectFromUrls($value, $off);
        }
    }

    private function collectFromUrls(string $value, bool $off): void
    {
        if (preg_match_all((string) $this->urlPattern, $value, $matches) === false) {
            return;
        }

        foreach ($matches[1] as $mediaId) {
            $this->foundMedia[(int) $off][(int) $mediaId] = true;
        }
    }

    /**
     * The library items behind media ids met by URL — one query for the ids
     * this scanner has not looked up yet; files of other models do not count.
     *
     * @param  array<int, int>  $mediaIds
     * @return array<int, int>
     */
    private function itemsOfMedia(array $mediaIds): array
    {
        $unknown = array_values(array_diff($mediaIds, array_keys($this->itemOfMedia)));

        if ($unknown !== []) {
            $mediaModel = config('media-library.media_model') ?: Media::class;
            $found = $mediaModel::query()
                ->whereKey($unknown)
                ->where('model_type', MediaLibrary::ITEM_MORPH_ALIAS)
                ->pluck('model_id', 'id')
                ->all();

            foreach ($unknown as $mediaId) {
                $this->itemOfMedia[$mediaId] = isset($found[$mediaId]) ? (int) $found[$mediaId] : null;
            }
        }

        $itemIds = [];

        foreach ($mediaIds as $mediaId) {
            if (($itemId = $this->itemOfMedia[$mediaId] ?? null) !== null) {
                $itemIds[] = $itemId;
            }
        }

        return $itemIds;
    }

    /**
     * Whether ids stored under the key count as references: a key the app
     * registered, or one named like a media field.
     */
    public static function recognizes(string $key): bool
    {
        return in_array($key, Cms::mediaReferenceKeys(), true) || self::namedLikeMedia($key);
    }

    private static function namedLikeMedia(string $key): bool
    {
        return preg_match(self::MEDIA_KEY_PATTERN, Str::snake($key)) === 1;
    }

    private function isMediaKey(string $key): bool
    {
        return $this->keys[$key] ??= self::namedLikeMedia($key);
    }

    private function collectFromHtml(string $html, bool $off): void
    {
        if (preg_match_all('/<img\b[^>]*?\bdata-id\s*=\s*(["\'])(.*?)\1/is', $html, $matches) === false) {
            return;
        }

        foreach ($matches[2] as $id) {
            $this->collect($this->withoutConversion(html_entity_decode($id, ENT_QUOTES | ENT_HTML5)), $off);
        }
    }

    private function collect(mixed $ref, bool $off): void
    {
        $id = MediaUrlResolver::normalize($ref);

        if (is_int($id) && $id > 0) {
            $this->found[(int) $off][$id] = true;
        }
    }

    /** `<key>|<conversion>` is how the picker stores a chosen conversion. */
    private function withoutConversion(mixed $id): mixed
    {
        return is_string($id) ? explode('|', $id, 2)[0] : $id;
    }
}
