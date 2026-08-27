<?php

namespace Mmoollllee\Cms\Support\Media;

use Filament\Facades\Filament;
use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\MediaLibrary\Authorization\LegacyPolicyAuthorization;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The CMS default driver for the media library (architecture mirrors the
 * proven nest.kuckuck.cam integration): the driver is the one place that owns
 * behavior — tenancy, disk, conversions, model — so apps swap or extend a
 * single class via {@see Cms::useMediaDriver()}.
 *
 * MUST NOT be autoloaded unless the plugin is installed
 * ({@see MediaLibrary::installed()}) — the parent class lives in the
 * optional ralphjsmit/laravel-filament-media-library package.
 */
class CmsMediaLibraryDriver extends MediaLibraryItemDriver
{
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
            });
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
