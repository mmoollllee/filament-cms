<?php

namespace Mmoollllee\Cms\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Mmoollllee\Cms\Cms;

/**
 * The dashboard's identity strip: which site am I editing, and where do I change
 * its settings.
 *
 * Deliberately just that. It used to also render a Domain/Firma/E-Mail/Standort
 * grid, which occupied the most valuable space on the dashboard with data an
 * editor never acts on — and which is fully editable one click away on the
 * tenant profile page anyway.
 */
class TenantOverviewWidget extends Widget
{
    protected string $view = 'cms::widgets.tenant-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $tenantClass = Cms::tenantModel();

        if (! $tenant instanceof $tenantClass) {
            return ['tenant' => null];
        }

        return [
            'tenant' => $tenant,
            'brandName' => $tenant->displayName(),
            'brandClaim' => $tenant->resolvedBrandClaim(),
            'logoUrl' => $tenant->resolvedMainLogoUrl(),
            'profileUrl' => Filament::getTenantProfileUrl(),
        ];
    }
}
