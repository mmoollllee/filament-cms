<?php

namespace Workbench\App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Models\Menu;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

/**
 * Native (base-CSS-styled) stats overview for the demo dashboard. Replaces the
 * package's Tailwind-themed widgets so the testbench dashboard looks polished
 * without a compiled panel theme.
 */
class DemoOverviewStats extends StatsOverviewWidget
{
    // Render eagerly (no Livewire lazy-load) so the stats appear on first paint.
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        $contentQuery = Cms::contentModel()::query();

        if ($tenant !== null) {
            $contentQuery->where('tenant_id', $tenant->getKey());
        }

        $total = (clone $contentQuery)->count();
        $published = (clone $contentQuery)
            ->whereNotNull('publish_from')
            ->where('publish_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('publish_until')->orWhere('publish_until', '>', now()))
            ->count();
        $types = (clone $contentQuery)->distinct()->count('content_type');

        return [
            Stat::make('Content', (string) $total)
                ->description($published.' published · '.($total - $published).' draft/scheduled')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary'),
            Stat::make('Content types', (string) $types)
                ->description('default.page/section, marketing.*')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('info'),
            Stat::make('Tenants', (string) Tenant::query()->count())
                ->description('Domain-based tenants')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('success'),
            Stat::make('Users', (string) User::query()->count())
                ->description('Superadmin · Admin · Editor')
                ->descriptionIcon('heroicon-m-users')
                ->color('warning'),
            Stat::make('Layout presets', (string) LayoutPreset::query()
                ->withoutGlobalScopes()
                ->availableTo($tenant)
                ->count())
                ->description('Global + tenant-owned class sets')
                ->descriptionIcon('heroicon-m-paint-brush')
                ->color('gray'),
            Stat::make('Fragments & menus', Fragment::query()->count().' / '.Menu::query()->count())
                ->description('Components & navigation')
                ->descriptionIcon('heroicon-m-puzzle-piece')
                ->color('gray'),
        ];
    }
}
