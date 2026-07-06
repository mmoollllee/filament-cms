<?php

namespace Mmoollllee\Cms\Sites\Concerns;

use Illuminate\Support\Facades\File;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use ReflectionClass;

/**
 * Auto-discovers Blueprint classes in subdirectories of the SiteExtension.
 *
 * Scans each immediate subdirectory for a `Blueprint.php` class implementing
 * ContentBlueprint. Merges discovered blueprints with inline blueprints
 * from the optional `inlineBlueprints()` method.
 */
trait DiscoversSiteBlueprints
{
    /** @var array<int, ContentBlueprint>|null Per-instance memo — blueprint discovery is static filesystem I/O. */
    private ?array $discoveredBlueprints = null;

    public function blueprints(): array
    {
        // Memoized: this runs in hot paths (every PathGenerator/ContentResolver
        // blueprint lookup, called per-row in listing loops). Extension instances are
        // reused via the SiteExtensionRegistry singleton, so the scan happens once per
        // request.
        return $this->discoveredBlueprints ??= [
            ...$this->discoverBlueprints(),
            ...(method_exists($this, 'inlineBlueprints') ? $this->inlineBlueprints() : []),
        ];
    }

    /**
     * @return array<int, ContentBlueprint>
     */
    protected function discoverBlueprints(): array
    {
        $reflection = new ReflectionClass($this);
        $baseDir = dirname($reflection->getFileName());
        $baseNamespace = $reflection->getNamespaceName();

        $blueprints = [];

        foreach (File::directories($baseDir) as $directory) {
            $dirName = basename($directory);
            $blueprintFile = $directory.'/Blueprint.php';

            if (! file_exists($blueprintFile)) {
                continue;
            }

            $class = $baseNamespace.'\\'.$dirName.'\\Blueprint';

            if (class_exists($class) && is_subclass_of($class, ContentBlueprint::class)) {
                $blueprints[] = app($class);
            }
        }

        return $blueprints;
    }
}
