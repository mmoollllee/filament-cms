<?php

use Livewire\Livewire;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Pages\Tenancy\EditTenantProfilePage;

/*
 * Renders the tenant-profile page in BOTH media modes. This page chains the
 * most field options onto MediaField results (helper texts, accepted types),
 * so it is the canary for "method exists on MediaPicker AND FileUpload" —
 * the original integration shipped a ->placeholder() chain that only exists
 * on FileUpload and crashed every picker-mode install on this exact page.
 */

it('renders the tenant profile page with the media library enabled', function () {
    actingAsMarketingPanelAdmin();

    Livewire::test(EditTenantProfilePage::class)
        ->assertSuccessful()
        ->assertSee('Main Logo')
        ->assertSee('Favicon');
});

it('renders the tenant profile page in classic upload mode', function () {
    Cms::disableMediaLibrary();
    actingAsMarketingPanelAdmin();

    Livewire::test(EditTenantProfilePage::class)
        ->assertSuccessful()
        ->assertSee('Main Logo')
        ->assertSee('Favicon');
});
