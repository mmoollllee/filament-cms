<?php

namespace Mmoollllee\Cms;

use Filament\Exceptions\NoDefaultPanelSetException;
use Filament\Facades\Filament;
use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Support\Content\Blocks\Contracts\BuilderBlock;
use Mmoollllee\Cms\Support\Content\Blocks\listing\ListingBlock;
use Mmoollllee\Cms\Support\Content\Blocks\media\MediaBlock;
use Mmoollllee\Cms\Support\Content\Blocks\section\SectionBlock;
use Mmoollllee\Cms\Support\Content\Blocks\text\TextBlock;
use RuntimeException;

/**
 * The app-facing configuration surface of the CMS engine.
 *
 * Apps register everything structural here from a service provider — models,
 * resources, builder blocks, site discovery, menu locations — following the
 * Cashier/Sanctum `use*Model()` convention instead of a config file. Panel
 * options (path, vite theme, …) are NOT set here: they live on the Panel in
 * the app's PanelProvider, Filament-style.
 *
 *     // app/Providers/CmsServiceProvider.php
 *     public function register(): void
 *     {
 *         Cms::useContentModel(Content::class);
 *         Cms::useTenantModel(Tenant::class);
 *         Cms::useFragmentModel(Fragment::class);
 *
 *         Cms::registerBlocks([...Cms::defaultBlocks(), MyBlock::class]);
 *     }
 *
 * Only environment-driven settings remain in config/cms.php (branding tenant,
 * dev-login prefill, redirect tunables).
 */
class Cms
{
    // -------------------------------------------------------------------------
    //  Models — concrete Eloquent classes the engine resolves at runtime
    //  (they implement the Mmoollllee\Cms\Contracts\* interfaces).
    // -------------------------------------------------------------------------

    /** @var class-string|null */
    protected static ?string $contentModel = null;

    /** @var class-string|null */
    protected static ?string $tenantModel = null;

    /** @var class-string|null */
    protected static ?string $userModel = null;

    /** @var class-string|null */
    protected static ?string $fragmentModel = null;

    /** @param  class-string  $model */
    public static function useContentModel(string $model): void
    {
        static::$contentModel = $model;
    }

    /** @param  class-string  $model */
    public static function useTenantModel(string $model): void
    {
        static::$tenantModel = $model;
    }

    /** @param  class-string  $model */
    public static function useUserModel(string $model): void
    {
        static::$userModel = $model;
    }

    /** @param  class-string  $model */
    public static function useFragmentModel(string $model): void
    {
        static::$fragmentModel = $model;
    }

    /**
     * @return class-string
     */
    public static function contentModel(): string
    {
        return static::$contentModel
            ?? throw new RuntimeException('No CMS content model registered. Call Cms::useContentModel() in a service provider.');
    }

    /**
     * @return class-string
     */
    public static function tenantModel(): string
    {
        return static::$tenantModel
            ?? throw new RuntimeException('No CMS tenant model registered. Call Cms::useTenantModel() in a service provider.');
    }

    /**
     * The authenticatable user model. Falls back to the framework auth config
     * when no model was registered explicitly.
     *
     * @return class-string
     */
    public static function userModel(): string
    {
        return static::$userModel ?? config('auth.providers.users.model');
    }

    /**
     * The reusable content-fragment model (implements Contracts\Fragment).
     * Null on installs that don't use fragments.
     *
     * @return class-string|null
     */
    public static function fragmentModel(): ?string
    {
        return static::$fragmentModel;
    }

    /**
     * Whether the app has registered the required concrete models. Until it
     * does, the model-dependent engine wiring (observers, policies) must not run.
     */
    public static function modelsConfigured(): bool
    {
        return static::$contentModel !== null && static::$tenantModel !== null;
    }

    // -------------------------------------------------------------------------
    //  Content resources
    // -------------------------------------------------------------------------

    /** @var class-string|null */
    protected static ?string $resourceBase = null;

    /** @var class-string|null */
    protected static ?string $contentResource = null;

    /**
     * Point the engine at an app-level base class that per-type content
     * resources extend. Site discovery only registers resources extending it.
     *
     * @param  class-string  $resource
     */
    public static function useResourceBase(string $resource): void
    {
        static::$resourceBase = $resource;
    }

    /**
     * @return class-string
     */
    public static function resourceBase(): string
    {
        return static::$resourceBase ?? TenantScopedContentResource::class;
    }

    /**
     * Replace the catch-all "Seiten" resource (registered in the panel and
     * linked by the dashboard widget) with an app subclass.
     *
     * @param  class-string  $resource
     */
    public static function useContentResource(string $resource): void
    {
        static::$contentResource = $resource;
    }

    /**
     * @return class-string
     */
    public static function contentResource(): string
    {
        return static::$contentResource ?? CatchAllContentResource::class;
    }

    // -------------------------------------------------------------------------
    //  Catch-all content form
    // -------------------------------------------------------------------------

    protected static bool $contentPageHeader = false;

    /**
     * Show the opt-in "Titelbereich" page header on the catch-all content form
     * (site resources `use RendersPageHeader` unconditionally instead).
     */
    public static function enableContentPageHeader(bool $enabled = true): void
    {
        static::$contentPageHeader = $enabled;
    }

    public static function hasContentPageHeader(): bool
    {
        return static::$contentPageHeader;
    }

    // -------------------------------------------------------------------------
    //  Site extensions
    // -------------------------------------------------------------------------

    protected static ?string $sitesPath = null;

    protected static ?string $sitesNamespace = null;

    /**
     * Where site extensions live and the root namespace they map to. Defaults
     * to app_path('Sites') / 'App\Sites' — call this only when they live
     * elsewhere (e.g. the package workbench).
     */
    public static function discoverSitesIn(string $path, string $namespace): void
    {
        static::$sitesPath = $path;
        static::$sitesNamespace = $namespace;
    }

    public static function sitesPath(): string
    {
        return static::$sitesPath ?? app_path('Sites');
    }

    public static function sitesNamespace(): string
    {
        return rtrim(static::$sitesNamespace ?? 'App\\Sites', '\\');
    }

    // -------------------------------------------------------------------------
    //  Builder blocks
    // -------------------------------------------------------------------------

    /** @var array<int, class-string<BuilderBlock>>|null */
    protected static ?array $blocks = null;

    /** @var array<string, array<int, string>> */
    protected static array $sectionChildAllowlists = [];

    /** @var array<string, array<int, string>> */
    protected static array $rootBlockAllowlists = [];

    /**
     * The builder blocks offered in the panel — the COMPLETE ordered list
     * (order = picker order), replacing the default set. Compose with
     * {@see defaultBlocks()} to keep the core blocks:
     *
     *     Cms::registerBlocks([...Cms::defaultBlocks(), HintBlock::class]);
     *
     * @param  array<int, class-string<BuilderBlock>>  $blocks
     */
    public static function registerBlocks(array $blocks): void
    {
        static::$blocks = $blocks;
    }

    /**
     * @return array<int, class-string<BuilderBlock>>
     */
    public static function blocks(): array
    {
        return static::$blocks ?? static::defaultBlocks();
    }

    /**
     * The core blocks the package ships.
     *
     * @return array<int, class-string<BuilderBlock>>
     */
    public static function defaultBlocks(): array
    {
        return [
            SectionBlock::class,
            MediaBlock::class,
            TextBlock::class,
            ListingBlock::class,
        ];
    }

    /**
     * Restrict which child blocks a section allows for the given site key
     * (unrestricted by default).
     *
     * @param  array<int, string>  $blockKeys
     */
    public static function allowSectionChildren(string $siteKey, array $blockKeys): void
    {
        static::$sectionChildAllowlists[$siteKey] = $blockKeys;
    }

    /**
     * The section child-block allowlist for a site key, or null when the site
     * is unrestricted.
     *
     * @return array<int, string>|null
     */
    public static function sectionChildAllowlist(?string $siteKey): ?array
    {
        return static::$sectionChildAllowlists[$siteKey] ?? null;
    }

    /**
     * The top-level blocks the page builder offers for the given site key
     * (defaults to `section` only).
     *
     * @param  array<int, string>  $blockKeys
     */
    public static function allowRootBlocks(string $siteKey, array $blockKeys): void
    {
        static::$rootBlockAllowlists[$siteKey] = $blockKeys;
    }

    /**
     * @return array<int, string>
     */
    public static function rootBlockAllowlist(?string $siteKey): array
    {
        return static::$rootBlockAllowlists[$siteKey] ?? ['section'];
    }

    // -------------------------------------------------------------------------
    //  Media library (optional ralphjsmit/laravel-filament-media-library)
    // -------------------------------------------------------------------------

    protected static bool $mediaLibraryDisabled = false;

    /** @var class-string|null */
    protected static ?string $mediaDriver = null;

    /** @var class-string|null */
    protected static ?string $mediaItemModel = null;

    protected static ?string $mediaDisk = null;

    /** @var array<string, string>|null */
    protected static ?array $mediaFolderNames = null;

    /**
     * Opt out of the media library integration even when the package is
     * installed (fields fall back to plain uploads, no panel page).
     */
    public static function disableMediaLibrary(bool $disabled = true): void
    {
        static::$mediaLibraryDisabled = $disabled;
    }

    public static function mediaLibraryDisabled(): bool
    {
        return static::$mediaLibraryDisabled;
    }

    /**
     * Swap the media-library driver (visibility scope, disk, conversions,
     * accepted types). Extend the package default to adjust behavior:
     *
     *     Cms::useMediaDriver(App\Support\Media\MyDriver::class);
     *
     * @param  class-string  $driver
     */
    public static function useMediaDriver(string $driver): void
    {
        static::$mediaDriver = $driver;
    }

    /**
     * @return class-string
     */
    public static function mediaDriver(): string
    {
        return static::$mediaDriver ?? Support\Media\CmsMediaLibraryDriver::class;
    }

    /**
     * Swap the media item model (must extend the plugin's MediaLibraryItem).
     * The driver re-registers the `filament_media_library_item` morph alias.
     *
     * @param  class-string  $model
     */
    public static function useMediaItemModel(string $model): void
    {
        static::$mediaItemModel = $model;
    }

    /**
     * @return class-string
     */
    public static function mediaItemModel(): string
    {
        return static::$mediaItemModel ?? \RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem::class;
    }

    /**
     * The disk media-library uploads are stored on. Defaults to `public` —
     * CMS sites serve media statically. Apps with a private library (policy-
     * gated serving à la nest) point this at their own disk.
     */
    public static function useMediaDisk(string $disk): void
    {
        static::$mediaDisk = $disk;
    }

    public static function mediaDisk(): string
    {
        return static::$mediaDisk ?? 'public';
    }

    /**
     * Rename the default per-tenant media folders (key => visible name).
     * Keys: `branding`, `pages`, `documents` — see MediaFolders.
     *
     * @param  array<string, string>  $names
     */
    public static function useMediaFolderNames(array $names): void
    {
        static::$mediaFolderNames = $names;
    }

    /**
     * @return array<string, string>
     */
    public static function mediaFolderNames(): array
    {
        return static::$mediaFolderNames ?? [
            Support\Media\MediaFolders::BRANDING => 'Branding',
            Support\Media\MediaFolders::PAGES => 'Seiten',
            Support\Media\MediaFolders::DOCUMENTS => 'Dokumente',
        ];
    }

    // -------------------------------------------------------------------------
    //  Menus
    // -------------------------------------------------------------------------

    /** @var array<string, string>|null */
    protected static ?array $menuLocations = null;

    /**
     * The menu locations offered in the menu builder (location => label).
     * Single source for the panel plugin AND the cache invalidation — a
     * location registered here is automatically cleared/warmed like the defaults.
     *
     * @param  array<string, string>  $locations
     */
    public static function useMenuLocations(array $locations): void
    {
        static::$menuLocations = $locations;
    }

    /**
     * @return array<string, string>
     */
    public static function menuLocations(): array
    {
        return static::$menuLocations ?? [
            'header' => 'Hauptmenü',
            'footer' => 'Sekundär-Navigation',
        ];
    }

    // -------------------------------------------------------------------------
    //  Frontend
    // -------------------------------------------------------------------------

    protected static ?string $footerTagline = null;

    /**
     * Short claim shown in the shared frontend footer (hidden by default).
     */
    public static function useFooterTagline(?string $tagline): void
    {
        static::$footerTagline = $tagline;
    }

    public static function footerTagline(): ?string
    {
        return static::$footerTagline;
    }

    // -------------------------------------------------------------------------
    //  Panel
    // -------------------------------------------------------------------------

    /**
     * The admin panel's route prefix, read from the registered default panel so
     * frontend code (404 renderer, tenant-visibility middleware) shares the
     * value the app set via `$panel->path()`. Falls back to 'panel' when no
     * panel is registered (e.g. an unconfigured install).
     */
    public static function panelPath(): string
    {
        try {
            return trim(Filament::getDefaultPanel()->getPath(), '/');
        } catch (NoDefaultPanelSetException) {
            return 'panel';
        }
    }

    // -------------------------------------------------------------------------
    //  Testing
    // -------------------------------------------------------------------------

    /**
     * Reset all registered state (useful between tests — statics survive the
     * per-test app rebuild, unlike config).
     */
    public static function flush(): void
    {
        static::$contentModel = null;
        static::$tenantModel = null;
        static::$userModel = null;
        static::$fragmentModel = null;
        static::$resourceBase = null;
        static::$contentResource = null;
        static::$contentPageHeader = false;
        static::$sitesPath = null;
        static::$sitesNamespace = null;
        static::$blocks = null;
        static::$sectionChildAllowlists = [];
        static::$rootBlockAllowlists = [];
        static::$menuLocations = null;
        static::$footerTagline = null;
        static::$mediaLibraryDisabled = false;
        static::$mediaDriver = null;
        static::$mediaItemModel = null;
        static::$mediaDisk = null;
        static::$mediaFolderNames = null;
        Support\Media\MediaLibrary::flush();
        Support\Media\MediaUrlResolver::flush();
        Support\Media\MediaFolders::flush();
    }
}
