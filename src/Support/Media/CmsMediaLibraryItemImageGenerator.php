<?php

namespace Mmoollllee\Cms\Support\Media;

use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use RalphJSmit\Filament\MediaLibrary\ImageGenerators\MediaLibraryItemImageGenerator;

/**
 * The picker's thumbnail generator, with the guard the vendor one lacks.
 *
 * `getImageGenerator()` picks the FIRST generator whose `supportsFile()` says
 * yes, and the vendor's says yes as soon as `generated_conversions` carries a
 * single `true`. That column records that a conversion RAN, not that it
 * produced anything: the media library registers `responsive`/`800`/`400`/
 * `thumb` for every item it stores, videos and PDFs included, so an uploaded
 * MP4 ends up flagged for image derivatives that were never written. The tile
 * then renders `<img src=".../conversions/…-800.webp">` against a file that is
 * not on the disk — six logged 404s from one video in one editing session.
 *
 * Refusing those files here lets the picker fall back to the file-type icon,
 * which is what it already shows for every other non-image.
 *
 * The predicate is {@see MediaUrlResolver::isProcessableImageMime()} — the one
 * that already keeps the `og` crop off non-rasterizable files, so the picker
 * and the frontend agree on what counts as an image. A processable image needs
 * no further check: its conversions may still be queued, and the inherited
 * generator then falls back to the original, which an `<img>` can display. Only
 * files that will never have an image derivative are refused, which is why the
 * URL side needs no override.
 */
class CmsMediaLibraryItemImageGenerator extends MediaLibraryItemImageGenerator
{
    public function supportsFile(FileData $file): bool
    {
        if (! parent::supportsFile($file)) {
            return false;
        }

        /** @var MediaLibraryItemDriver\FileData $file */
        $media = $file->getSource()->getItem();

        if ($media === null) {
            return false;
        }

        return MediaUrlResolver::isProcessableImageMime($media->mime_type);
    }
}
