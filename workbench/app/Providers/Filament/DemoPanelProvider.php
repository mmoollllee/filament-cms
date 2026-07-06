<?php

namespace Workbench\App\Providers\Filament;

use Filament\Panel;
use Mmoollllee\Cms\Filament\Providers\BasePanelProvider;
use Workbench\App\Filament\Pages\Dashboard;

/**
 * Demo admin panel for the testbench: a thin subclass of the package
 * BasePanelProvider. Resources (catch-all content, fragments, core, site
 * extensions) and the RichEditor stack are the package defaults — the demo
 * overrides only the dashboard and its page discovery.
 */
class DemoPanelProvider extends BasePanelProvider
{
    /**
     * Replace the package dashboard with the demo dashboard (styled native stats +
     * a pointer to the Documentation page). The Documentation page itself is picked
     * up by discoverPages() below.
     *
     * @return array<int, class-string>
     */
    protected function panelPages(): array
    {
        return [
            Dashboard::class,
        ];
    }

    protected function configurePanel(Panel $panel): Panel
    {
        return $panel->discoverPages(
            in: dirname(__DIR__, 2).'/Filament/Pages',
            for: 'Workbench\\App\\Filament\\Pages',
        );
    }
}
