<?php

namespace Mmoollllee\Cms\Sites;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\SiteExtension;
use Mmoollllee\Cms\Sites\Default\SiteExtension as DefaultSiteExtension;

/**
 * Auto-discovers and manages all SiteExtension implementations.
 *
 * Scans the registered sites path (Cms::sitesPath(), default app_path('Sites'))
 * for PHP files named `SiteExtension.php` that implement the SiteExtension
 * contract, mapping each file to a class under the registered root namespace
 * (Cms::sitesNamespace(), default 'App\Sites'). Results are cached for the
 * request.
 *
 * forSite(?string $siteKey) always includes the 'default' extension plus the
 * extension matching the given site key (if any). This ensures every tenant
 * gets the base content types plus its own specialized ones.
 *
 * @see SiteExtension — the discovered contract
 * @see ContentBlueprintRegistry — aggregates blueprints from extensions
 */
class SiteExtensionRegistry
{
    /**
     * @var array<string, SiteExtension>|null
     */
    protected ?array $extensions = null;

    public function __construct(
        protected Application $app,
    ) {}

    /**
     * @return array<string, SiteExtension>
     */
    public function all(): array
    {
        if ($this->extensions !== null) {
            return $this->extensions;
        }

        // The package ships the always-present 'default' extension (default.page /
        // default.section). Seed it first so an app-level App\Sites\Default\SiteExtension,
        // if present, overrides it by site key during discovery below.
        $extensions = [
            'default' => $this->app->make(DefaultSiteExtension::class),
        ];

        $sitesPath = Cms::sitesPath();
        $rootNamespace = Cms::sitesNamespace();

        if (! is_dir($sitesPath)) {
            return $this->extensions = $extensions;
        }

        foreach (File::allFiles($sitesPath) as $file) {
            if ($file->getFilename() !== 'SiteExtension.php') {
                continue;
            }

            $relativePath = Str::after($file->getPathname(), $sitesPath.DIRECTORY_SEPARATOR);

            $class = $rootNamespace.'\\'.str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $relativePath,
            );

            if (is_subclass_of($class, SiteExtension::class) === false) {
                continue;
            }

            /** @var SiteExtension $extension */
            $extension = $this->app->make($class);
            $extensions[$extension->siteKey()] = $extension;
        }

        return $this->extensions = $extensions;
    }

    /**
     * Aggregates all resource classes from all extensions.
     *
     * @return array<int, class-string>
     */
    public function allResources(): array
    {
        $resources = [];

        foreach ($this->all() as $extension) {
            foreach ($extension->resources() as $resource) {
                $resources[] = $resource;
            }
        }

        return array_values(array_unique($resources));
    }

    /**
     * @return array<int, SiteExtension>
     */
    public function forSite(?string $siteKey): array
    {
        $extensions = [];
        $all = $this->all();

        if (isset($all['default'])) {
            $extensions[] = $all['default'];
        }

        if ($siteKey !== null && $siteKey !== 'default' && isset($all[$siteKey])) {
            $extensions[] = $all[$siteKey];
        }

        return $extensions;
    }
}
