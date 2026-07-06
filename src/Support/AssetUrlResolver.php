<?php

namespace Mmoollllee\Cms\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Resolves a stored asset path to a publicly accessible URL.
 *
 * - null/blank path → null
 * - array (e.g. a FileUpload state) → first element is used
 * - absolute URL or root-relative path → returned as-is
 * - relative storage path → resolved against the public disk (`/storage/{path}`)
 */
class AssetUrlResolver
{
    public static function resolve(string|array|null $path): ?string
    {
        // Filament FileUpload keeps its state as an array (even for a single file),
        // which is what block previews receive before the value is dehydrated.
        if (is_array($path)) {
            $path = Arr::first($path);
        }

        if (! filled($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
            return $path;
        }

        return '/storage/'.$path;
    }
}
