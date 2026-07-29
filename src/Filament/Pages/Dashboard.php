<?php

namespace Mmoollllee\Cms\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Mmoollllee\Cms\Filament\Widgets\ContentOverviewWidget;
use Mmoollllee\Cms\Filament\Widgets\PendingContentWidget;
use Mmoollllee\Cms\Filament\Widgets\RecentVersionsWidget;
use Mmoollllee\Cms\Filament\Widgets\TenantOverviewWidget;
use Mmoollllee\Cms\Support\Analytics\Umami;
use Mmoollllee\Filami\Filament\Widgets\UmamiEventsWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    /**
     * Ordered by how much the editor needs it: who am I editing, what is
     * waiting on me, what exists, how is it doing (optional Umami analytics),
     * what changed. The pending widget hides itself when there is nothing to
     * do, the analytics widgets while filami is missing or unconfigured.
     *
     * Array order IS render order here: Filament sorts by $sort only for
     * panel-registered widgets, whereas a page-level getWidgets() override is
     * mapped as-is (Page::getWidgetsSchemaComponents() only filters by
     * canView()). The analytics widgets must therefore be spliced into place
     * rather than appended.
     */
    public function getWidgets(): array
    {
        return [
            TenantOverviewWidget::class,
            PendingContentWidget::class,
            ContentOverviewWidget::class,
            ...(Umami::installed() ? [
                UmamiStatsOverviewWidget::class,
                UmamiVisitorsChartWidget::class,
                UmamiTopPagesWidget::class,
                UmamiEventsWidget::class,
            ] : []),
            RecentVersionsWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
