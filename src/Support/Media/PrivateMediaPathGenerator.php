<?php

namespace Mmoollllee\Cms\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

/**
 * `{media-id}-{token}/` for library media that carry a token, Spatie's plain
 * `{media-id}/` for everything else — so files stored before the private
 * library existed are found where they are ({@see PrivateMediaPaths}).
 *
 * Conversions and srcset candidates live below the same directory, so the
 * token hides them as well.
 */
class PrivateMediaPathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        $token = PrivateMediaPaths::token($media);

        return $token === null ? parent::getBasePath($media) : parent::getBasePath($media).'-'.$token;
    }
}
