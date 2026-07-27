<?php

use Livewire\Livewire;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Filament\Pages\Tenancy\EditTenantProfilePage;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;
use Workbench\App\Models\Tenant;

/**
 * Who may open and save the tenant settings page. Both tenant roles qualify —
 * Editors run the site day to day (branding, contact data, SEO defaults), only
 * user management stays admin-only. The page delegates to TenantPolicy::update(),
 * and the same gate hides the tenant-menu entry, so these tests pin both.
 */
it('lets a tenant editor open the tenant settings page', function () {
    $tenant = actingAsMarketingPanelUser('editor-a@example.test');

    // Guards the test itself: it only proves anything while that account really
    // is an Editor (and not silently seeded/promoted to Admin).
    expect(auth()->user()->tenantRole($tenant))->toBe(TenantUserRole::Editor)
        ->and(EditTenantProfilePage::canView($tenant))->toBeTrue();

    Livewire::test(EditTenantProfilePage::class)
        ->assertSuccessful()
        ->assertSee('Markenauftritt');
});

it('lets a tenant editor save the tenant settings', function () {
    $tenant = actingAsMarketingPanelUser('editor-a@example.test');

    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['brand_claim' => 'Vom Editor gepflegt'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->brand_claim)->toBe('Vom Editor gepflegt');
});

it('keeps the tenant settings page open for tenant admins', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');

    expect(EditTenantProfilePage::canView($tenant))->toBeTrue();
});

it('denies the tenant settings page to users outside the tenant', function () {
    $tenant = actingAsMarketingPanelUser('admin-b@example.test');

    expect(EditTenantProfilePage::canView($tenant))->toBeFalse();
});

it('denies the tenant settings page when unauthenticated', function () {
    expect(EditTenantProfilePage::canView(Tenant::factory()->create()))->toBeFalse();
});

it('still keeps user management away from editors', function () {
    // The other half of the rule: widening the settings page to Editors must not
    // widen the UserResource with it — that gate stays on TenantUserRole::Admin.
    actingAsMarketingPanelUser('editor-a@example.test');

    expect(UserResource::canAccess())->toBeFalse()
        ->and(UserResource::canCreate())->toBeFalse();

    actingAsMarketingPanelUser('admin-a@example.test');

    expect(UserResource::canAccess())->toBeTrue();
});
