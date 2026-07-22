<?php

namespace Mmoollllee\Cms\Filament\Forms;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Mmoollllee\Cms\Support\Media\MediaFolders;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
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
        // find(), not ensure(): the closure is evaluated on every form render,
        // and a render must never (re)create folders behind the editor's back.
        return MediaPicker::make($name)
            ->acceptedFileTypes($acceptedFileTypes)
            ->defaultFolder(fn () => MediaFolders::find($folderKey));
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
