<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Tests\TestCase;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * Shared panel-test bootstrap: seeds the demo, selects the panel, signs in the
 * seeded superadmin and primes the marketing tenant. Returns that tenant.
 */
function actingAsMarketingPanelAdmin(): Tenant
{
    test()->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    test()->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setTenant($tenant);
    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}
