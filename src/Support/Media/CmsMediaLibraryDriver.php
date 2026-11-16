<?php

namespace Mmoollllee\Cms\Support\Media;

use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Filament\Support\Exceptions\Halt;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Support\MediaUsageList;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Data\TemporaryFileUploadData;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\Explore\Filament\Schemas\Components\LabelValueEntry;
use RalphJSmit\Filament\MediaLibrary\Authorization\LegacyPolicyAuthorization;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The CMS default driver for the media library: the driver is the one place
 * that owns behavior — tenancy, disk, conversions, model — so apps swap or
 * extend a single class via {@see Cms::useMediaDriver()}.
 *
 * Files live in Spatie's `{media-id}/` layout, or — the private media library,
 * on by default — in `{media-id}-{token}/` with a random token
 * ({@see PrivateMediaPaths}). Tenant isolation lives in the database (the
 * item's tenant morph), not in the path. Spatie computes paths at runtime, so
 * a layout of its own (tenant folders, say) would compute new paths for every
 * file an app's library already stored and find none of them there; the
 * token layout avoids that by falling back to `{media-id}/` for every file
 * without a token. `cms:media:prune` knows both layouts: it is how the command
 * tells library directories from everything else on the disk. An app that
 * needs its media kept apart gives the library its own disk
 * ({@see Cms::useMediaDisk()}), not its own path generator.
 *
 * MUST NOT be autoloaded unless the plugin is installed
 * ({@see MediaLibrary::installed()}) — the parent class lives in the
 * optional ralphjsmit/laravel-filament-media-library package.
 */
class CmsMediaLibraryDriver extends MediaLibraryItemDriver
{
    /**
     * The conversion that carries the srcset the frontend renders. Named here
     * because this is where it is registered; {@see MediaUrlResolver::srcset()}
     * reads it back and `cms:media:prune` decides what is dead weight by it.
     */
    public const RENDERED_CONVERSION = 'responsive';

    /**
     * The key the library registers srcset candidates under when they are
     * generated off the original rather than off a conversion. Spatie spells it
     * as a literal in half a dozen places and exposes no constant.
     */
    public const ORIGINAL_LEVEL = 'media_library_original';

    /**
     * MIME type of the media whose conversions are being registered. The vendor
     * calls the modify callbacks with the conversion only, so the register
     * callback that runs first in the same pass notes it here.
     */
    protected ?string $conversionMediaMimeType = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaLibraryItemModel(Cms::mediaItemModel());

        // The plugin wires Gate policies onto the drivers IT instantiates
        // (panel boot). Field-injected instances would skip authorization
        // without this re-bridge.
        if (LegacyPolicyAuthorization::hasPolicies()) {
            $this->authorizeUsing(LegacyPolicyAuthorization::toClosure());
        }

        $format = static::conversionFormat();
        $ogFormat = static::ogConversionFormat();
        $maxWidth = static::responsiveMaxWidth();

        $this
            ->mediaCollection(fn (MediaCollection $collection) => $collection
                ->useDisk(Cms::mediaDisk())
                ->storeConversionsOnDisk(Cms::mediaDisk()))
            // Frontend output relies on pre-generated files (srcset, og:image,
            // CDN-cacheable), so force conversions on even though Glide is
            // available for the panel preview.
            ->conversions(true)
            // Applies to the vendor-registered conversions (`responsive`,
            // `800`, `400`, `thumb`) — NOT to `og`, which is registered below
            // and formatted separately.
            ->modifyConversionsUsing(fn (Conversion $conversion) => $conversion->format($format))
            // The srcset candidates are generated FROM the `responsive`
            // conversion's output file, so capping that one file caps every
            // candidate with it — without a cap the vendor emits candidates up
            // to the original's width (a 4000px phone photo yields several
            // multi-megabyte steps no viewport ever requests).
            ->conversionResponsiveModifyUsing(
                $maxWidth > 0
                    ? fn (Conversion $conversion) => $conversion->fit(Fit::Max, $maxWidth, $maxWidth)
                    : null
            )
            // Runs first in every registerMediaConversions() pass, before the
            // vendor adds its conversions and calls the modify callbacks.
            ->registerConversions(function (MediaLibraryItem $item, ?Media $media): void {
                $this->conversionMediaMimeType = $media?->mime_type;
            })
            // A video's stills are cut by ffmpeg. The vendor makes `thumb`
            // synchronously, inside the upload request — where a PHP-FPM pool
            // locked down with open_basedir cannot reach the binary, and the
            // video ends up without a panel preview. Queued, the worker makes
            // every still of a video a moment later, whatever the app's queue
            // default.
            ->modifyConversionsUsing(function (Conversion $conversion): void {
                if (str_starts_with((string) $this->conversionMediaMimeType, 'video/')) {
                    $conversion->queued();
                }
            })
            ->registerConversions(function (MediaLibraryItem $item, ?Media $media) use ($ogFormat): void {
                // Processable images only: the library also accepts PDFs,
                // videos, SVGs and ICOs — a 1200×630 GD crop on those would
                // enqueue failing conversion jobs (the vendor model documents
                // the same mime guard; SVG/ICO exclusion mirrors the
                // resolver's srcset rule).
                if ($media !== null && ! MediaUrlResolver::isProcessableImageMime($media->mime_type)) {
                    return;
                }

                $item->addMediaConversion('og')->fit(Fit::Crop, 1200, 630)->format($ogFormat);
            })
            // Where a file is used — the question to answer before deleting or
            // replacing it — and how far a video's web conversion has got.
            ->fileInfoInformationUsing(fn (array $components, FileData $file): array => [
                ...$components,
                ...$this->usageInformation($file),
            ]);
    }

    /**
     * Nothing a record still uses is deleted: not the file, not a folder
     * holding it. Checked for everything the call would remove BEFORE the
     * first removal, so a refused folder stays whole. The editor gets the
     * list of uses instead ({@see MediaDeletionGuard}).
     */
    public function deleteFile(FileData $file): void
    {
        $blocked = MediaDeletionGuard::blockedBy([$file], $this);

        if ($blocked !== []) {
            MediaDeletionGuard::notify($blocked);

            throw new Halt;
        }

        parent::deleteFile($file);
    }

    /**
     * One swap of an item's file at a time: a video conversion finishing
     * while the editor replaces the file would drop the replacement
     * ({@see LibraryVideos::exclusively()}).
     */
    public function replaceFile(FileData $file, TemporaryFileUploadData $temporaryFileUploadData): FileData
    {
        return LibraryVideos::exclusively((int) $file->getPrimaryKey(), fn (): FileData => parent::replaceFile($file, $temporaryFileUploadData));
    }

    /**
     * @return array<int, LabelValueEntry>
     */
    protected function usageInformation(FileData $file): array
    {
        if ($file->getType() !== FileType::File) {
            return [];
        }

        $item = $file->getSource();

        if (! $item instanceof MediaLibraryItem) {
            return [];
        }

        $usages = MediaReferences::shown($item);
        $entries = [
            LabelValueEntry::make('Verwendet auf')->schema([
                Text::make($usages->isEmpty() ? 'Nirgends – die Datei kann gelöscht werden.' : MediaUsageList::html($usages)),
            ]),
        ];

        if (($video = LibraryVideos::statusLabel($item->getFirstMedia($item->getMediaLibraryCollectionName()))) !== null) {
            $entries[] = LabelValueEntry::make('Video')->schema([Text::make($video)]);
        }

        return $entries;
    }

    /**
     * Longest edge the `responsive` conversion is capped to; 0 = no cap.
     * Read per driver instantiation so tests and apps can retune it without
     * re-registering the driver.
     */
    public static function responsiveMaxWidth(): int
    {
        return max(0, (int) config('cms.media.max_width', 1920));
    }

    /**
     * Format for the frontend conversions. Responsive candidates inherit the
     * format from the conversion file they derive from, so this governs the
     * srcset as well.
     */
    public static function conversionFormat(): string
    {
        return (string) config('cms.media.format', 'webp');
    }

    /**
     * Format for the `og` conversion. Deliberately separate: messenger
     * link-preview crawlers still lag on WebP.
     */
    public static function ogConversionFormat(): string
    {
        return (string) config('cms.media.og_format', 'jpg');
    }

    /**
     * Tenancy is active whenever a tenant context exists — panel requests
     * always have one (native Filament tenancy). No per-registration
     * `->tenancy()` call needed; frontend code resolves items by id and never
     * goes through the driver.
     */
    public function hasTenancy(): bool
    {
        return $this->tenant !== null || Filament::getTenant() !== null;
    }
}
