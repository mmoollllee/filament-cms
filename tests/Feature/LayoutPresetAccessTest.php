<?php

use Mmoollllee\Cms\Filament\Resources\LayoutPresets\LayoutPresetResource;
use Workbench\App\Models\User;

/**
 * LayoutPresets are a shared, cross-tenant resource. Only superadmins may see and manage the
 * LayoutPresetResource (create/edit/delete); every other user can only *use* presets via the
 * select field. These tests pin the resource-access gate.
 */
it('hides the LayoutPreset resource from non-superadmins', function () {
    $this->actingAs(User::factory()->create());

    expect(LayoutPresetResource::canAccess())->toBeFalse()
        ->and(LayoutPresetResource::shouldRegisterNavigation())->toBeFalse();
});

it('grants the LayoutPreset resource to superadmins', function () {
    $this->actingAs(User::factory()->superadmin()->create());

    expect(LayoutPresetResource::canAccess())->toBeTrue()
        ->and(LayoutPresetResource::shouldRegisterNavigation())->toBeTrue();
});

it('denies access when unauthenticated', function () {
    expect(LayoutPresetResource::canAccess())->toBeFalse();
});

it('lists GLOBAL presets despite panel tenancy', function () {
    // Presets are a shared pool: tenant_id null = global. Filament's tenant scoping
    // would filter those out and leave the resource table empty — the resource
    // must opt out of tenancy scoping.
    \Mmoollllee\Cms\Models\LayoutPreset::create(['scope' => ['section'], 'title' => 'Global', 'classes' => 'x']);

    $this->actingAs(User::factory()->superadmin()->create());
    $tenant = \Workbench\App\Models\Tenant::factory()->create();
    \Filament\Facades\Filament::setTenant($tenant);

    expect(LayoutPresetResource::isScopedToTenant())->toBeFalse()
        ->and(LayoutPresetResource::getEloquentQuery()->count())->toBe(1);
});
