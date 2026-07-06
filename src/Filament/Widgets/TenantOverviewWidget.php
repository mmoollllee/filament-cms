<?php

namespace Mmoollllee\Cms\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Mmoollllee\Cms\Cms;

class TenantOverviewWidget extends Widget
{
    protected string $view = 'cms::widgets.tenant-overview';

    protected int|string|array $columnSpan = 'full';

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
            'companyName' => $tenant->resolvedSiteSetting('company_name'),
            'contactEmail' => $tenant->resolvedSiteSetting('contact_email'),
            'contactPhone' => $tenant->resolvedSiteSetting('contact_phone'),
            'city' => $tenant->resolvedSiteSetting('city'),
            'domain' => $tenant->primary_domain,
            'logoUrl' => $tenant->resolvedMainLogoUrl(),
            'userCount' => $tenant->users()->count(),
            'profileUrl' => Filament::getTenantProfileUrl(),
        ];
    }
}
