<?php

namespace Mmoollllee\Cms\Support\Assets;

/**
 * Cache-busting version = content hash of the asset.
 *
 * Filament's default version for a package asset is the installed COMPOSER
 * version, which does not change while a package is being worked on and does
 * not change for a patch that only touches an asset. The URL then stays
 * byte-identical while the file behind it changes, and every browser that has
 * seen it keeps serving its cached copy — the asset is sent with far-future
 * cache headers. The symptom is a CSS or JS fix that "does not work" for
 * everyone who visited the panel before it.
 *
 * The PUBLISHED copy is hashed when it exists — it is what the browser is
 * actually served. Hashing the source instead would rotate the URL before
 * `filament:assets` copies the new content over, poisoning long-lived caches
 * with the OLD bytes under the NEW version string.
 *
 * Mirrors the same trait in mmoollllee/filament-media-library-extensions;
 * duplicated rather than imported because that package is optional here.
 */
trait HasContentHashVersion
{
    protected ?string $contentHashVersion = null;

    public function getVersion(): string
    {
        return $this->contentHashVersion ??= $this->resolveContentHashVersion();
    }

    protected function resolveContentHashVersion(): string
    {
        foreach ([$this->getPublicPath(), $this->getPath()] as $path) {
            if (is_file($path)) {
                return (string) md5_file($path);
            }
        }

        return parent::getVersion();
    }
}
