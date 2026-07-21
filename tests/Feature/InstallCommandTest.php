<?php

use Illuminate\Support\Facades\File;

/**
 * cms:install writes into the testbench skeleton (config, app/Models, routes,
 * bootstrap/providers.php, database/migrations). Everything is snapshotted and
 * restored so the scaffolding never leaks into other tests — published
 * migrations in particular would otherwise join every later migrate run.
 */
function installCommandSandbox(callable $callback): void
{
    $createdTargets = [
        config_path('cms.php'),
        app_path('Models/Content.php'),
        app_path('Models/Tenant.php'),
        app_path('Models/Fragment.php'),
        app_path('Providers/CmsServiceProvider.php'),
        app_path('Providers/Filament/PanelProvider.php'),
    ];

    $mutatedFiles = [
        base_path('routes/web.php'),
        app()->getBootstrapProvidersPath(),
    ];

    $backups = [];

    foreach ($mutatedFiles as $path) {
        $backups[$path] = File::exists($path) ? File::get($path) : null;
    }

    // The testbench skeleton ships no routes/web.php — seed an empty one so the
    // append path is exercised (a null backup deletes it again on cleanup).
    if (! File::exists(base_path('routes/web.php'))) {
        File::put(base_path('routes/web.php'), "<?php\n\nuse Illuminate\Support\Facades\Route;\n");
    }

    foreach ($createdTargets as $path) {
        File::delete($path);
    }

    $migrationsBefore = collect(File::files(database_path('migrations')))->map->getPathname();

    try {
        $callback();
    } finally {
        foreach ($createdTargets as $path) {
            File::delete($path);
        }

        foreach ($backups as $path => $content) {
            $content === null ? File::delete($path) : File::put($path, $content);
        }

        collect(File::files(database_path('migrations')))
            ->map->getPathname()
            ->diff($migrationsBefore)
            ->each(fn (string $path) => File::delete($path));
    }
}

it('scaffolds config, models, providers and frontend routes', function () {
    installCommandSandbox(function () {
        $this->artisan('cms:install')->assertSuccessful();

        expect(File::exists(config_path('cms.php')))->toBeTrue()
            ->and(File::exists(app_path('Models/Content.php')))->toBeTrue()
            ->and(File::exists(app_path('Models/Tenant.php')))->toBeTrue()
            ->and(File::exists(app_path('Models/Fragment.php')))->toBeTrue()
            ->and(File::exists(app_path('Providers/CmsServiceProvider.php')))->toBeTrue()
            ->and(File::exists(app_path('Providers/Filament/PanelProvider.php')))->toBeTrue();

        // The scaffolded CMS provider registers the models in code (the published
        // config carries only environment-driven settings) …
        expect(File::get(app_path('Providers/CmsServiceProvider.php')))
            ->toContain('Cms::useContentModel(Content::class)')
            ->toContain('Cms::useTenantModel(Tenant::class)')
            ->toContain('Cms::useFragmentModel(Fragment::class)');
        expect(File::get(config_path('cms.php')))->toContain('CMS_DEV_LOGIN_EMAIL');

        // … the scaffolded models adopt the package traits (not copied code) …
        expect(File::get(app_path('Models/Tenant.php')))->toContain('use InheritsBranding;')
            ->and(File::get(app_path('Models/Content.php')))->toContain('use GeneratesPathAndSlug;')
            // The draft/preview workflow ships enabled: both scaffolded models
            // must adopt HasDraft, or fresh installs silently lose the feature.
            ->and(File::get(app_path('Models/Content.php')))->toContain('use HasDraft;')
            ->and(File::get(app_path('Models/Fragment.php')))->toContain('use HasDraft;');

        // … the frontend catch-all is appended and both providers registered.
        expect(File::get(base_path('routes/web.php')))->toContain('ContentShowController');
        expect(File::get(app()->getBootstrapProvidersPath()))
            ->toContain('App\Providers\CmsServiceProvider::class')
            ->toContain('App\Providers\Filament\PanelProvider::class');
    });
});

it('skips existing files unless --force is given', function () {
    installCommandSandbox(function () {
        File::ensureDirectoryExists(app_path('Models'));
        File::put(app_path('Models/Tenant.php'), '<?php // custom tenant');

        $this->artisan('cms:install')
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();

        expect(File::get(app_path('Models/Tenant.php')))->toBe('<?php // custom tenant');

        $this->artisan('cms:install', ['--force' => true])->assertSuccessful();

        expect(File::get(app_path('Models/Tenant.php')))->toContain('use InheritsBranding;');
    });
});

it('does not duplicate the frontend routes on a second run', function () {
    installCommandSandbox(function () {
        $this->artisan('cms:install')->assertSuccessful();
        $this->artisan('cms:install')->assertSuccessful();

        expect(substr_count(File::get(base_path('routes/web.php')), 'content.show'))->toBe(1);
    });
});
