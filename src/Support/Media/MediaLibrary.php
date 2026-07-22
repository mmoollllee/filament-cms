<?php

namespace Mmoollllee\Cms\Support\Media;

use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;

/**
 * Capability gate for the optional media-library integration
 * (ralphjsmit/laravel-filament-media-library, commercial).
 *
 * The package only *suggests* the dependency: every media-library code path —
 * panel plugin, picker fields, policies, resolver ID-lookups — gates on
 * {@see enabled()} and falls back to the classic FileUpload/path behavior when
 * the plugin is absent. Installs that have the plugin but want the classic
 * behavior can opt out via {@see Cms::disableMediaLibrary()}.
 *
 * IMPORTANT: classes that extend plugin classes (driver, preview action) must
 * never be autoloaded unless {@see installed()} is true.
 */
final class MediaLibrary
{
    private static ?bool $installedMemo = null;

    /** Whether the plugin package is installed (composer-level). */
    public static function installed(): bool
    {
        return self::$installedMemo ??= class_exists(FilamentMediaLibrary::class);
    }

    /** Whether the integration is active: installed and not opted out. */
    public static function enabled(): bool
    {
        return static::installed() && ! Cms::mediaLibraryDisabled();
    }

    /** Reset the memo ({@see Cms::flush()} calls this between tests). */
    public static function flush(): void
    {
        self::$installedMemo = null;
    }
}
