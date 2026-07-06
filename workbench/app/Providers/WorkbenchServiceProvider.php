<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\Cms\Cms;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;
use Workbench\App\Support\Content\Blocks\hint\HintBlock;

/**
 * Wires the demo workbench app into the CMS engine, mirroring a consuming
 * app's App\Providers\CmsServiceProvider (see stubs/cms-provider.stub): the
 * concrete models, the workbench's out-of-app Sites location, the demo block
 * registration and the opt-in page header. boot() registers the demo's
 * frontend Blade views + block view paths, exactly like a consuming app.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Cms::useContentModel(Content::class);
        Cms::useTenantModel(Tenant::class);
        Cms::useFragmentModel(Fragment::class);

        // The workbench's Sites live outside app_path().
        Cms::discoverSitesIn(dirname(__DIR__, 2).'/app/Sites', 'Workbench\\App\\Sites');

        // The four core blocks + the demo's own HintBlock — the complete
        // "register a custom block" recipe (see /howto/custom-blocks).
        Cms::registerBlocks([...Cms::defaultBlocks(), HintBlock::class]);

        // Opt-in "Titelbereich" page header on the catch-all content form.
        Cms::enableContentPageHeader();
    }

    public function boot(): void
    {
        // PREPEND, don't append: a real app's resources/views is registered before
        // any package location, so its views win over the package fallbacks. The
        // workbench must mirror that — appended, the package's frontend/standalone
        // fallback would shadow the demo's own shell.
        $this->app['view']->getFinder()->prependLocation(dirname(__DIR__, 2).'/resources/views');

        // App-side blocks: add the demo's block directory to the same namespaces the
        // package uses — `blocks::hint.preview` (panel) + `<x-block::hint>` (frontend).
        $blocks = dirname(__DIR__, 2).'/resources/blocks';

        $this->loadViewsFrom($blocks, 'blocks');
        Blade::anonymousComponentPath($blocks, 'block');
    }
}
