<?php

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriver;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\Conversions\Conversion;

/*
 * Storage cost of the media library is set here and nowhere else: every image
 * carries five conversions plus one srcset candidate per responsive step, so
 * the format and the responsive cap decide whether a site's uploads occupy
 * their own size or twenty times it. These tests pin the conversion
 * DEFINITIONS (not the generated files) — the definition is what the library
 * derives file names, formats and candidate widths from.
 */

/**
 * The conversions the CMS driver registers, keyed by name. Built off a bare
 * item so no disk write is needed: `registerMediaConversions(null)` is the
 * same call the library makes per media, with the mime guard short-circuited
 * to "processable" by the null media.
 *
 * @return array<string, Conversion>
 */
function cmsRegisteredConversions(): array
{
    $item = new (Cms::mediaItemModel());
    $item->driver(app(Cms::mediaDriver()));
    $item->registerMediaConversions(null);

    return collect($item->mediaConversions)
        ->keyBy(fn (Conversion $conversion) => $conversion->getName())
        ->all();
}

it('registers the frontend conversions plus og', function () {
    expect(array_keys(cmsRegisteredConversions()))
        ->toEqualCanonicalizing(['responsive', '800', '400', 'thumb', 'og']);
});

it('caps the responsive conversion to the configured max width', function () {
    $responsive = cmsRegisteredConversions()['responsive'];

    // Fit::Max preserves the aspect ratio and never upsizes, so the cap is a
    // ceiling for large originals rather than a resize for everything.
    expect($responsive->getManipulations()->getManipulationArgument('fit'))
        ->toBe([Fit::Max, 1920, 1920]);
});

it('honours a custom max width', function () {
    config()->set('cms.media.max_width', 1280);

    expect(cmsRegisteredConversions()['responsive']->getManipulations()->getManipulationArgument('fit'))
        ->toBe([Fit::Max, 1280, 1280]);
});

it('leaves the responsive conversion uncapped when max width is zero', function () {
    config()->set('cms.media.max_width', 0);

    // No fit manipulation at all — the vendor default (candidates up to the
    // original's width) is restored for installs that want it.
    expect(cmsRegisteredConversions()['responsive']->getManipulations()->getManipulationArgument('fit'))
        ->toBeNull();
});

it('formats the frontend conversions as webp', function () {
    $conversions = cmsRegisteredConversions();

    // The library seeds every conversion with format('jpg') in its
    // constructor; the driver's callback must win over that default.
    foreach (['responsive', '800', '400', 'thumb'] as $name) {
        expect($conversions[$name]->getManipulations()->getFirstManipulationArgument('format'))
            ->toBe('webp', "conversion [{$name}] should be webp");
    }
});

it('keeps og as jpg so link-preview crawlers can read it', function () {
    expect(cmsRegisteredConversions()['og']->getManipulations()->getFirstManipulationArgument('format'))
        ->toBe('jpg');
});

it('honours a custom conversion format', function () {
    config()->set('cms.media.format', 'jpg');
    config()->set('cms.media.og_format', 'png');

    $conversions = cmsRegisteredConversions();

    expect($conversions['responsive']->getManipulations()->getFirstManipulationArgument('format'))->toBe('jpg')
        ->and($conversions['og']->getManipulations()->getFirstManipulationArgument('format'))->toBe('png');
});

it('drives the conversion file names off the configured format', function () {
    // The result extension is what the prune command compares disk files
    // against — a format change must rename, not silently reuse.
    $responsive = cmsRegisteredConversions()['responsive'];

    expect($responsive->getResultExtension('png'))->toBe('webp');
});

it('exposes the settings as driver accessors', function () {
    config()->set('cms.media.max_width', 2560);
    config()->set('cms.media.format', 'jpg');
    config()->set('cms.media.og_format', 'png');

    expect(CmsMediaLibraryDriver::responsiveMaxWidth())->toBe(2560)
        ->and(CmsMediaLibraryDriver::conversionFormat())->toBe('jpg')
        ->and(CmsMediaLibraryDriver::ogConversionFormat())->toBe('png');
});

it('clamps a negative max width to no cap', function () {
    config()->set('cms.media.max_width', -100);

    expect(CmsMediaLibraryDriver::responsiveMaxWidth())->toBe(0)
        ->and(cmsRegisteredConversions()['responsive']->getManipulations()->getManipulationArgument('fit'))
        ->toBeNull();
});
