<?php

namespace Mmoollllee\Cms\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mmoollllee\Cms\Cms;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        // The frontend layout emits @vite unguarded; the testbench has no build.
        $this->withoutVite();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Workbench\\Database\\Factories\\'.class_basename($modelName).'Factory',
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The CMS engine wiring lives in static registries (not config), which
        // survive the per-test app rebuild. Flush so per-test overrides never
        // leak; WorkbenchServiceProvider re-registers the workbench wiring
        // (models, sites, blocks, page header) on the next app boot.
        Cms::flush();
    }
}
