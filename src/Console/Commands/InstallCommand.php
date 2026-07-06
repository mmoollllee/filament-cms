<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

/**
 * Scaffolds the CMS into a fresh Laravel app: publishes config + migrations,
 * writes the model/provider stubs, appends the frontend routes and publishes
 * the Filament assets. Idempotent — existing files are skipped (use --force
 * to overwrite), so re-running on a configured app is safe.
 */
class InstallCommand extends Command
{
    protected $signature = 'cms:install
        {--force : Overwrite existing files (models, panel provider, published config)}';

    protected $description = 'Scaffold the CMS: config, migrations, models, panel provider, frontend routes, Filament assets';

    public function handle(Filesystem $files): int
    {
        $force = (bool) $this->option('force');

        $this->components->info('Installing mmoollllee/filament-cms …');

        // 1. Config (environment-driven settings only) + migrations via the
        //    regular publish tags.
        $this->callSilently('vendor:publish', ['--tag' => 'cms-config', '--force' => $force]);
        $this->components->task('config/cms.php published (tag: cms-config)');

        $this->callSilently('vendor:publish', ['--tag' => 'cms-migrations']);
        $this->components->task('migrations published (tag: cms-migrations)');

        // 2. Model + provider scaffolding from the package stubs. The structural
        //    engine wiring (models, blocks, …) lives in the scaffolded
        //    App\Providers\CmsServiceProvider; it must be registered before the
        //    panel provider so the wiring is in place when the panel is built.
        $this->scaffold($files, 'content.model.stub', app_path('Models/Content.php'), $force);
        $this->scaffold($files, 'tenant.model.stub', app_path('Models/Tenant.php'), $force);
        $this->scaffold($files, 'fragment.model.stub', app_path('Models/Fragment.php'), $force);

        if ($this->scaffold($files, 'cms-provider.stub', app_path('Providers/CmsServiceProvider.php'), $force)) {
            ServiceProvider::addProviderToBootstrapFile(\App\Providers\CmsServiceProvider::class);
            $this->components->task('CmsServiceProvider registered in bootstrap/providers.php');
        }

        if ($this->scaffold($files, 'panel-provider.stub', app_path('Providers/Filament/PanelProvider.php'), $force)) {
            ServiceProvider::addProviderToBootstrapFile(\App\Providers\Filament\PanelProvider::class);
            $this->components->task('PanelProvider registered in bootstrap/providers.php');
        }

        // 3. Frontend routes (idempotent: skipped when the catch-all is already wired).
        $this->appendFrontendRoutes($files);

        // 4. The panel needs the package JS/CSS in public/ (assets are copied, not
        //    symlinked — re-run `php artisan filament:assets` after package updates).
        $this->callSilently('filament:assets');
        $this->components->task('Filament assets published');

        $this->printNextSteps();

        return self::SUCCESS;
    }

    /** Copy a stub unless the target exists (or --force). Returns whether it was written. */
    protected function scaffold(Filesystem $files, string $stub, string $target, bool $force): bool
    {
        $relative = str_replace(base_path().'/', '', $target);

        if ($files->exists($target) && ! $force) {
            $this->components->twoColumnDetail($relative, '<fg=yellow>exists, skipped (use --force)</>');

            return false;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy(__DIR__.'/../../../stubs/'.$stub, $target);
        $this->components->task($relative.' created');

        return true;
    }

    /**
     * Append the tenant-scoped frontend routes (robots, sitemap, /_content and the
     * content catch-all) to routes/web.php. Skipped when a ContentShowController
     * route is already registered — the catch-all must stay LAST, so apps that
     * already wire it keep their own ordering.
     */
    protected function appendFrontendRoutes(Filesystem $files): void
    {
        $routesPath = base_path('routes/web.php');

        if (! $files->exists($routesPath)) {
            $this->components->twoColumnDetail('routes/web.php', '<fg=yellow>not found, skipped</>');

            return;
        }

        if (str_contains($files->get($routesPath), 'ContentShowController')) {
            $this->components->twoColumnDetail('routes/web.php', '<fg=yellow>frontend routes already wired, skipped</>');

            return;
        }

        $files->append($routesPath, $files->get(__DIR__.'/../../../stubs/routes.stub'));
        $this->components->task('frontend routes appended to routes/web.php');
    }

    protected function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('Next steps');

        foreach ([
            'Add the CMS user wiring to app/Models/User.php: implement Filament\Models\Contracts\{FilamentUser, HasTenants, HasDefaultTenant} and Mmoollllee\Cms\Contracts\User, then `use Mmoollllee\Cms\Concerns\User\BelongsToTenants;`. Keep is_superadmin OUT of $fillable.',
            'Run `php artisan migrate` (the published migrations add is_superadmin to users and create the CMS tables).',
            'Create the first tenant + user, e.g. via tinker: $t = \App\Models\Tenant::create([\'name\' => \'Meine Site\', \'site_key\' => \'default\', \'primary_domain\' => \'meine-site.test\', \'visibility\' => \'public\']); $u = \App\Models\User::create([\'name\' => \'Admin\', \'email\' => \'admin@example.com\', \'password\' => \'secret\']); $u->forceFill([\'is_superadmin\' => true])->save();',
            'Open /panel on the tenant domain. Content types, blocks and layout presets are ready — customize via app/Providers/CmsServiceProvider.php and app/Sites/ (see docs/CUSTOMIZATION.md).',
        ] as $step) {
            $this->components->bulletList([$step]);
        }
    }
}
