<?php

namespace Mmoollllee\Cms\Sites\Concerns;

use Illuminate\Support\Facades\File;
use Mmoollllee\Cms\Cms;
use ReflectionClass;

/**
 * Auto-discovers Filament Resource classes in the SiteExtension directory.
 *
 * Scans the `Resources/` directory (flat) and all subdirectories of the
 * extension for files named `Resource.php` that extend the application's
 * content resource base (Cms::resourceBase()).
 *
 * Supports both structures:
 * - `Resources/JobResource.php` (flat, legacy)
 * - `Job/Resource.php` (per-type folder, new)
 */
trait DiscoversSiteResources
{
    public function resources(): array
    {
        $resourceBase = Cms::resourceBase();

        $reflection = new ReflectionClass($this);
        $baseDir = dirname($reflection->getFileName());
        $baseNamespace = $reflection->getNamespaceName();

        $resources = [];

        // Scan Resources/ directory (flat, legacy pattern)
        $resourcesDir = $baseDir.'/Resources';

        if (is_dir($resourcesDir)) {
            foreach (File::files($resourcesDir) as $file) {
                if (! str_ends_with($file->getFilename(), 'Resource.php')) {
                    continue;
                }

                $class = $baseNamespace.'\\Resources\\'.$file->getFilenameWithoutExtension();

                if (class_exists($class) && is_subclass_of($class, $resourceBase)) {
                    $resources[] = $class;
                }
            }
        }

        // Scan subdirectories for Resource.php (per-type folder pattern)
        foreach (File::directories($baseDir) as $directory) {
            $dirName = basename($directory);

            if ($dirName === 'Resources' || $dirName === 'Forms' || $dirName === 'Concerns') {
                continue;
            }

            $resourceFile = $directory.'/Resource.php';

            if (! file_exists($resourceFile)) {
                continue;
            }

            $class = $baseNamespace.'\\'.$dirName.'\\Resource';

            if (class_exists($class) && is_subclass_of($class, $resourceBase)) {
                $resources[] = $class;
            }
        }

        return $resources;
    }
}
