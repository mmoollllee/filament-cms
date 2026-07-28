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
     * Ordered by how much the editor needs it: who am I editing (sort 0), what
     * is waiting on me (10), what exists (20), what changed (40). The pending
     * widget hides itself when there is nothing to do.
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
