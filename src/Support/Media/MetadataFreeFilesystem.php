<?php

namespace Mmoollllee\Cms\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The library's filesystem, dropping camera metadata from every derivative on its way to the disk.
 *
 * Conversions and srcset candidates both reach the media disk through copyToMediaLibrary() as
 * local temp files, typed 'conversions' / 'responsiveImages' — the one place every derivative
 * passes, whichever image driver made it and whichever disk it lands on. The Imagick driver
 * copies the source's EXIF (GPS coordinates, device, capture time, an embedded preview) into
 * each of them; the uploaded original arrives untyped and keeps everything.
 */
class MetadataFreeFilesystem extends Filesystem
{
    public function copyToMediaLibrary(string $pathToFile, Media $media, ?string $type = null, ?string $targetFileName = null): void
    {
        if (in_array($type, ['conversions', 'responsiveImages'], true)) {
            ImageMetadata::stripFile($pathToFile);
        }

        parent::copyToMediaLibrary($pathToFile, $media, $type, $targetFileName);
    }
}
