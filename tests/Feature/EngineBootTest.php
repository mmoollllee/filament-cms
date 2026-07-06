<?php

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

it('resolves the cms models from config', function () {
    expect(Cms::tenantModel())->toBe(Tenant::class)
        ->and(Cms::contentModel())->toBe(Content::class);
});

it('discovers the workbench site extensions', function () {
    $registry = app(SiteExtensionRegistry::class);

    expect(array_keys($registry->all()))
        ->toContain('default')
        ->toContain('marketing');
});

it('exposes blueprints from the discovered extensions', function () {
    $registry = app(SiteExtensionRegistry::class);

    $keys = collect($registry->forSite('marketing'))
        ->flatMap(fn ($ext) => $ext->blueprints())
        ->map(fn ($bp) => $bp->key())
        ->all();

    expect($keys)
        ->toContain('default.page')
        ->toContain('marketing.service');
});
