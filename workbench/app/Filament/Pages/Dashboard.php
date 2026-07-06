<?php

namespace Workbench\App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Workbench\App\Filament\Widgets\DemoOverviewStats;

/**
 * Demo dashboard — replaces the package dashboard in the testbench with a styled,
 * native stats overview and a pointer to the public docs website (the frontend),
 * where every feature is demonstrated and documented.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    public function getSubheading(): string|Htmlable|null
    {
        // The documentation lives on the FRONTEND (the filament-cms docs site).
        return new HtmlString(
            'filament-cms demo — all features are set up. '
            .'<a href="/features" target="_blank" style="color:#d97706; font-weight:600; text-decoration:underline;">→ To the docs website (frontend)</a>'
        );
    }

    public function getWidgets(): array
    {
        return [
            DemoOverviewStats::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
