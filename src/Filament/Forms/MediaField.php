<?php

namespace Mmoollllee\Cms\Filament\Forms;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Str;
use LogicException;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\MediaFolders;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use Mmoollllee\Cms\Support\Media\References\MediaReferenceScanner;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;

/**
 * The one way CMS forms reference media — returns a MediaPicker when the
 * optional media library is active, a classic tenant-scoped FileUpload
 * otherwise. Both store into the SAME data key (item id vs. storage path;
 * {@see \Mmoollllee\Cms\Support\Media\MediaUrlResolver} renders either), so
 * installing the plugin later changes no data shape.
 *
 * Call sites may only chain methods that exist on BOTH components (label,
 * helperText, visible, live, required, acceptedFileTypes, …) — NOT
 * ->placeholder(), which FileUpload has but MediaPicker lacks. Everything
 * component-specific (disk, directory, editor, preview height, default
 * folder) is owned here; the `imagePreviewHeight`/`imageEditor` parameters
 * only affect the FileUpload fallback (the picker's editor lives in the
 * library UI). tests/Feature/MediaTenantProfileRenderTest.php renders the
 * heaviest call site in both modes to catch contract drift.
 *
 * A picker's key must be one the Mediathek's delete protection reads
 * ({@see MediaReferenceScanner::recognizes()}): named like a media field, or
 * registered. Checked where the field is written — outside production a
 * wrong name throws, in production it is reported — so no field ends up
 * storing files the Mediathek would let the editor delete while a page
 * shows them.
 */
final class MediaField
{
    /** Raster-only set for e-mail images (SVG doesn't render in Gmail/Outlook). */
    public const RASTER_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public const FAVICON_TYPES = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml', 'image/png'];

    public const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];

    public const DOCUMENT_TYPES = [
        'application/pdf',
        'application/zip',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** @var array<string, true> unread keys already reported by this process */
    private static array $reported = [];

    /** Extension counterpart of DOCUMENT_TYPES (importer folder routing). */
    public const DOCUMENT_EXTENSIONS = ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

    /**
     * Every extension the legacy importer treats as CMS media — the image,
     * video and document sets this factory accepts. Anything else
     * (`sitemap.xml`, `export.csv`, …) is NOT a media reference and must
     * never be imported/rewritten, even when the file exists on the disk.
     */
    public const IMPORTABLE_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico',
        'mp4', 'webm', 'mov', 'ogg',
        'pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    ];

    /** An image field (block images, hero, branding). */
    public static function image(
        string $name,
        string|Closure|null $legacyDirectory = null,
        string $folderKey = MediaFolders::PAGES,
        string $imagePreviewHeight = '120',
        bool $imageEditor = false,
    ): Field {
        if (MediaLibrary::enabled()) {
            return static::picker($name, ['image/*'], $folderKey);
        }

        $upload = static::upload($name, $legacyDirectory, $imagePreviewHeight)
            ->image();

        if ($imageEditor) {
            $upload->imageEditor();
        }

        return $upload;
    }

    /** A mixed image/video field (the media block). */
    public static function media(
        string $name,
        string|Closure|null $legacyDirectory = null,
        string $folderKey = MediaFolders::PAGES,
        string $imagePreviewHeight = '120',
    ): Field {
        $types = ['image/*', ...self::VIDEO_TYPES];

        if (MediaLibrary::enabled()) {
            return static::picker($name, $types, $folderKey);
        }

        return static::upload($name, $legacyDirectory, $imagePreviewHeight)
            ->acceptedFileTypes($types);
    }

    /** A download/document field (PDF, ZIP, Office). */
    public static function document(
        string $name,
        string|Closure|null $legacyDirectory = null,
        string $folderKey = MediaFolders::DOCUMENTS,
    ): Field {
        if (MediaLibrary::enabled()) {
            return static::picker($name, self::DOCUMENT_TYPES, $folderKey);
        }

        return static::upload($name, $legacyDirectory, imagePreviewHeight: null)
            ->acceptedFileTypes(self::DOCUMENT_TYPES);
    }

    protected static function picker(string $name, array $acceptedFileTypes, string $folderKey): Field
    {
        self::assertTracked($name);

        // find(), not ensure(): the closure is evaluated on every form render,
        // and a render must never (re)create folders behind the editor's back.
        return MediaPicker::make($name)
            ->acceptedFileTypes($acceptedFileTypes)
            ->defaultFolder(fn () => MediaFolders::find($folderKey))
            // A file deleted before the Mediathek protected used files left
            // its id behind. The picker shows nothing for it; saving the form
            // as shown drops it, and nothing on the page changes.
            ->afterStateHydrated(function (MediaPicker $component, mixed $state): void {
                $kept = self::withoutMissing($state);

                if ($kept !== $state) {
                    $component->state($kept);
                }
            })
            // A form opened before someone deleted the file it then picks.
            ->rule(fn (MediaPicker $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                if (self::withoutMissing($value) !== $value) {
                    $fail($component->isMultiple()
                        ? 'Eine der gewählten Dateien wurde inzwischen gelöscht. Bitte wähle die Dateien neu aus.'
                        : 'Die gewählte Datei wurde inzwischen gelöscht. Bitte wähle eine andere aus.');
                }
            });
    }

    /**
     * The picker's state without the items that are gone. Anything that
     * does not read as an item id is left alone.
     */
    private static function withoutMissing(mixed $state): mixed
    {
        $entries = is_array($state) ? $state : [$state];
        $ids = [];

        foreach ($entries as $entry) {
            if (is_int($id = self::itemId($entry))) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return $state;
        }

        // Unscoped: whether the item exists at all, not whether this request may list it.
        $existing = array_flip(array_map('intval', Cms::mediaItemModel()::query()
            ->withoutGlobalScopes()
            ->whereKey(array_keys($ids))
            ->pluck((new (Cms::mediaItemModel()))->getKeyName())
            ->all()));

        if (count($existing) === count($ids)) {
            return $state;
        }

        $kept = array_filter($entries, fn (mixed $entry): bool => ! is_int($id = self::itemId($entry)) || isset($existing[$id]));

        return is_array($state) ? (array_is_list($state) ? array_values($kept) : $kept) : null;
    }

    private static function itemId(mixed $entry): ?int
    {
        // `<key>|<conversion>` is how a picker stores a chosen conversion.
        $id = MediaUrlResolver::normalize(is_string($entry) ? explode('|', $entry, 2)[0] : $entry);

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * Outside production a picker under a key the delete protection does not
     * read throws; in production it is reported once per process.
     */
    private static function assertTracked(string $name): void
    {
        $key = Str::afterLast($name, '.');

        if (MediaReferenceScanner::recognizes($key)) {
            return;
        }

        $exception = new LogicException(
            "MediaField [{$name}]: the Mediathek does not read the key [{$key}] for references, so it would let the editor delete "
            ."a file picked here while a page shows it. Name the field like a media field ([{$key}_image], [{$key}_bild], …) "
            ."or register the key: Cms::registerMediaReferenceKeys(['{$key}']). A field stored in a table of the app's own "
            .'also needs a MediaReferenceSource for that table.'
        );

        if (! app()->isProduction()) {
            throw $exception;
        }

        if (! isset(self::$reported[$key])) {
            self::$reported[$key] = true;

            report($exception);
        }
    }

    protected static function upload(string $name, string|Closure|null $legacyDirectory, ?string $imagePreviewHeight): FileUpload
    {
        $upload = FileUpload::make($name)
            ->disk('public')
            ->visibility('public');

        if ($legacyDirectory !== null) {
            $upload->directory($legacyDirectory);
        }

        if ($imagePreviewHeight !== null) {
            $upload->imagePreviewHeight($imagePreviewHeight);
        }

        return $upload;
    }
}
