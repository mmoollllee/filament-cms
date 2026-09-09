<?php

namespace Mmoollllee\Cms\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Application;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Filami\Filami;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // The workbench demo content and the shipped assertions are German —
        // pin the locale so `cms::` lang strings resolve to lang/de instead
        // of the testbench default ('en').
        $app['config']->set('app.locale', 'de');

        // SQLite enforces foreign keys only on request, and several behaviours exist
        // BECAUSE of a `nullOnDelete` side effect — contents.parent_id stranding a
        // subtree, redirects.to_content_id losing its target. Without this they would be
        // verified against a database that never performs the thing they compensate for.
        $connection = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }

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

        // Same for the optional Umami integration's registry (require-dev
        // here) — CmsServiceProvider re-runs autoProvision() on the next boot.
        if (class_exists(Filami::class)) {
            Filami::flush();
        }
    }
}
