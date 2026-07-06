<?php

namespace Mmoollllee\Cms\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Mmoollllee\Cms\Filament\Widgets\ContentOverviewWidget;
use Mmoollllee\Cms\Filament\Widgets\TenantOverviewWidget;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    public function getWidgets(): array
    {
        return [
            TenantOverviewWidget::class,
            ContentOverviewWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
