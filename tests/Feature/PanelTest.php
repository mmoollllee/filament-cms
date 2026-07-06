<?php

use Filament\Facades\Filament;
use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Filament\Resources\LayoutPresets\LayoutPresetResource;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Workbench\App\Filament\Pages\Dashboard as DemoDashboard;
use Workbench\App\Sites\Marketing\Service\Resource as ServiceResource;

it('builds the demo panel with package core + catch-all + fragment + site-extension resources', function () {
    $resources = Filament::getPanel('panel')->getResources();

    expect($resources)
        ->toContain(UserResource::class)              // package core
        ->toContain(LayoutPresetResource::class)      // package core
        ->toContain(CatchAllContentResource::class)   // package catch-all (registered directly)
        ->toContain(FragmentResource::class)          // package fragments (registered directly)
        ->toContain(ServiceResource::class);          // site-extension (marketing)
});

it('registers the styled demo dashboard in place of the package one', function () {
    $pages = Filament::getPanel('panel')->getPages();

    expect($pages)
        ->toContain(DemoDashboard::class)       // styled demo dashboard (replaces package one)
        ->not->toContain(\Mmoollllee\Cms\Filament\Pages\Dashboard::class);
});

it('scopes site-extension resources to the matching tenant site_key', function () {
    $registry = app(SiteExtensionRegistry::class);

    $marketing = collect($registry->forSite('marketing'))
        ->flatMap(fn ($extension) => $extension->resources())
        ->all();

    $default = collect($registry->forSite('default'))
        ->flatMap(fn ($extension) => $extension->resources())
        ->all();

    expect($marketing)->toContain(ServiceResource::class)
        ->and($default)->not->toContain(ServiceResource::class);
});
