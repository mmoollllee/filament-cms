<?php

namespace Mmoollllee\Cms\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Mmoollllee\Cms\Filament\Widgets\ContentOverviewWidget;
use Mmoollllee\Cms\Filament\Widgets\PendingContentWidget;
use Mmoollllee\Cms\Filament\Widgets\RecentVersionsWidget;
use Mmoollllee\Cms\Filament\Widgets\TenantOverviewWidget;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    /**
     * Ordered by how much the editor needs it: who am I editing, what is
     * waiting on me, what exists, what changed. The pending widget hides
     * itself when there is nothing to do.
     *
     * Analytics is deliberately NOT here: reach numbers answer a different
     * question than "what should I work on", and four widgets pushed the
     * changelog below the fold. They live on their own page
     * ({@see \Mmoollllee\Filami\Filament\Pages\UmamiStatistics}, registered
     * in BasePanelProvider).
     *
     * Array order IS render order here: Filament sorts by $sort only for
     * panel-registered widgets, whereas a page-level getWidgets() override is
     * mapped as-is (Page::getWidgetsSchemaComponents() only filters by
     * canView()).
     */
    public function getWidgets(): array
    {
        return [
            TenantOverviewWidget::class,
            PendingContentWidget::class,
            ContentOverviewWidget::class,
            RecentVersionsWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
