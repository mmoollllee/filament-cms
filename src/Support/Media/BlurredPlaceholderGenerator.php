<?php

namespace Mmoollllee\Cms\Support\Media;

use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\TinyPlaceholderGenerator;
use Spatie\MediaLibrary\Support\ImageFactory;

/**
 * The library's blurred 32px placeholder, without the source's metadata.
 *
 * The stock generator resizes and saves, and the Imagick driver carries every profile of the
 * source into the result: the camera's EXIF including its embedded preview JPEG, ICC, XMP. A
 * phone photo turned a ~1 KB placeholder into 40+ KB, and since the placeholder travels inline
 * in every page (base64 inside a base64 SVG), one gallery page carried 200 KB of it.
 *
 * Even the colour profile goes: at 32 blurred pixels it changes nothing a visitor could see.
 * The pixels are already upright — the driver applies the EXIF orientation on load — so the
 * orientation tag goes with the rest.
 */
class BlurredPlaceholderGenerator implements TinyPlaceholderGenerator
{
    public function generateTinyPlaceholder(string $sourceImagePath, string $tinyImageDestinationPath): void
    {
        ImageFactory::load($sourceImagePath)->width(32)->blur(5)->save($tinyImageDestinationPath);

        ImageMetadata::stripFile($tinyImageDestinationPath, keepColorProfile: false);
    }
}
