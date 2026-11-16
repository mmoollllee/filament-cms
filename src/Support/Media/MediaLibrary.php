<?php

namespace Mmoollllee\Cms\Support\Media;

use Illuminate\Database\Eloquent\Relations\Relation;
use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
    /** Morph alias the plugin registers for its item model. */
    public const ITEM_MORPH_ALIAS = 'filament_media_library_item';

    private static ?bool $installedMemo = null;

    private static ?bool $extensionsMemo = null;

    /** Whether the plugin package is installed (composer-level). */
    public static function installed(): bool
    {
        return self::$installedMemo ??= class_exists(FilamentMediaLibrary::class);
    }

    /** Whether a media row belongs to a library item, and not to some other model of the app. */
    public static function isLibraryMedia(Media $media): bool
    {
        $type = (string) $media->model_type;

        return $type === self::ITEM_MORPH_ALIAS
            || is_a(Relation::getMorphedModel($type) ?? $type, MediaLibraryItem::class, true);
    }

    /** Whether the integration is active: installed and not opted out. */
    public static function enabled(): bool
    {
        return static::installed() && ! Cms::mediaLibraryDisabled();
    }

    /**
     * Whether mmoollllee/filament-media-library-extensions is installed
     * (upload button, inline/dropzone uploads, auto-selection, extended
     * preview). Its service provider wires the picker UX itself via
     * configureUsing(); the CMS only switches the default driver to the
     * trait-carrying subclass ({@see Cms::mediaDriver()}).
     */
    public static function extensionsInstalled(): bool
    {
        return self::$extensionsMemo ??= static::installed()
            && class_exists(\Mmoollllee\FilamentMediaLibraryExtensions\FilamentMediaLibraryExtensionsServiceProvider::class);
    }

    /** Reset the memos ({@see Cms::flush()} calls this between tests). */
    public static function flush(): void
    {
        self::$installedMemo = null;
        self::$extensionsMemo = null;
    }
}
