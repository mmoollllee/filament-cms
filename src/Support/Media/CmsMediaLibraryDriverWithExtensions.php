<?php

namespace Mmoollllee\Cms\Support\Media;

use Mmoollllee\FilamentMediaLibraryExtensions\Drivers\Concerns\HasMediaLibraryExtensions;

/**
 * {@see CmsMediaLibraryDriver} plus the opt-in hooks of
 * mmoollllee/filament-media-library-extensions: created files are recorded
 * for auto-selection, and the extended preview action covers modal file
 * tiles and the file info sidebar.
 *
 * Separate subclass because the trait lives in the OPTIONAL extensions
 * package — this class MUST NOT be autoloaded unless
 * {@see MediaLibrary::extensionsInstalled()} is true (trait resolution
 * happens at class load time). {@see \Mmoollllee\Cms\Cms::mediaDriver()}
 * picks it automatically when available.
 */
class CmsMediaLibraryDriverWithExtensions extends CmsMediaLibraryDriver
{
    use HasMediaLibraryExtensions;
}
