<?php

namespace Mmoollllee\Cms\Support;

use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

/**
 * Resolves a stored asset reference to a publicly accessible URL.
 *
 * - null/blank → null
 * - array (e.g. a FileUpload state) → first element is used
 * - media-library item id (int/numeric string) → resolved via the Spatie
 *   Media API ({@see MediaUrlResolver}), optionally to a named conversion
 * - absolute URL or root-relative path → returned as-is
 * - relative storage path → resolved against the public disk (`/storage/{path}`)
 *
 * Kept as the stable façade every view/accessor already uses — the
 * media-library awareness lives in MediaUrlResolver behind it.
 */
class AssetUrlResolver
{
    public static function resolve(string|int|array|null $path, ?string $conversion = null): ?string
    {
        return MediaUrlResolver::url($path, $conversion);
    }
}
