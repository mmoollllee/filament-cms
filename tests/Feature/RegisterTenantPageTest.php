<?php

use Livewire\Livewire;
use Mmoollllee\Cms\Enums\TenantVisibility;
use Mmoollllee\Cms\Filament\Pages\Tenancy\RegisterTenantPage;
use Workbench\App\Models\Tenant;

/*
 * Creating a site ("Tenant anlegen", superadmins only). Its times follow the app
 * (APP_TIMEZONE), so the form asks for no timezone of its own.
 */

it('creates a site without asking for a timezone', function () {
    actingAsMarketingPanelAdmin();

    Livewire::test(RegisterTenantPage::class)
        ->assertFormFieldDoesNotExist('timezone')
        ->fillForm([
            'name' => 'Neue Site',
            'site_key' => 'neue-site',
            'primary_domain' => 'neue-site.test',
            'visibility' => TenantVisibility::Public->value,
            'default_locale' => 'de',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Tenant::query()->where('primary_domain', 'neue-site.test')->exists())->toBeTrue();
});
