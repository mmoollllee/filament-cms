<?php

use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\ListContents;
use Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $this->tenant = Tenant::factory()->create([
        'primary_domain' => 'localhost',
        'site_key' => 'default',
    ]);

    $this->user = User::factory()->create(['is_superadmin' => true]);
    $this->tenant->users()->attach($this->user, ['role' => 'admin']);

    Content::factory()->create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Startseite',
        'path' => '/',
    ]);

    $this->actingAs($this->user);
    Filament::setTenant($this->tenant);
});

it('keeps ResolveTenantFromHost running on Livewire update requests (the fix)', function () {
    // Filament makes IdentifyTenant persistent by default, so Filament::getTenant()
    // survives /livewire/update. The CMS CurrentTenant singleton is populated by
    // ResolveTenantFromHost, which must be persistent too — otherwise it is null on
    // every Livewire interaction and the content policy / canAccess() 403s.
    expect(Livewire::getPersistentMiddleware())
        ->toContain(ResolveTenantFromHost::class);
});

it('403s the content resource when the tenant is unresolved (the dependency the fix protects)', function () {
    // Reproduces the broken state: Filament's tenant is set, but CurrentTenant is
    // null (ResolveTenantFromHost did not run). This is exactly what a Livewire
    // update looked like before the persistent-middleware fix.
    expect(app(CurrentTenant::class)->get())->toBeNull();
    expect(Filament::getTenant())->not->toBeNull();

    Livewire::test(ListContents::class)->assertStatus(403);
});
