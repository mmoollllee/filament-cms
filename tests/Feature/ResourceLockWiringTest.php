<?php

/*
 * Panel-level wiring of editorial locking (BasePanelProvider::resourceLockPlugin()).
 *
 * The tenancy assertion is the load-bearing one: Filament gives every
 * tenant-scoped resource a tenancy global scope on its MODEL, and neither lock
 * model has a tenant relationship — leaving them scoped throws a LogicException
 * on every lock query, i.e. locking would break itself the moment it is used.
 */

use Blendbyte\FilamentResourceLock\Http\Livewire\ResourceLockObserver;
use Blendbyte\FilamentResourceLock\Resources\AuditResource as ResourceLockAuditResource;
use Datlechin\FilamentMenuBuilder\FilamentMenuBuilderPlugin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Locking\ResourceLockPlugin;
use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Filament\Resources\Locks\LockResource;
use Mmoollllee\Cms\Filament\Resources\Menus\MenuResource;
use Mmoollllee\Cms\Filament\Resources\Menus\Pages\EditMenu;
use Mmoollllee\Cms\Support\Locking\Locks;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

it('registers the locking plugin on the panel', function () {
    expect(Filament::getCurrentPanel()->hasPlugin(Locks::PLUGIN_ID))->toBeTrue()
        ->and(Locks::active())->toBeTrue();
});

it('unscopes the lock manager WITHOUT unscoping every other resource', function () {
    // $isScopedToTenant lives on the Filament\Resources\Resource base class, so
    // a subclass that does not redeclare it shares one storage slot with every
    // resource in the app: calling LockResource::scopeToTenant(false) there
    // switches tenant scoping off panel-wide. The engine's LockResource
    // redeclares the property instead — this pins that it stayed per-class.
    expect(LockResource::isScopedToTenant())->toBeFalse()
        ->and(FragmentResource::isScopedToTenant())->toBeTrue()
        ->and(MenuResource::isScopedToTenant())->toBeTrue()
        ->and(CatchAllContentResource::isScopedToTenant())->toBeTrue();
});

it('leaves the audit resource — ungated and table-less — off the panel', function () {
    $resources = Filament::getCurrentPanel()->getResources();

    expect($resources)->toContain(LockResource::class)
        ->and($resources)->not->toContain(ResourceLockAuditResource::class);
});

it('bridges the lock timeout into the config the model actually reads', function () {
    // HasLocks::getLockTimeout() consults the vendor config, never the plugin.
    expect(config('filament-resource-lock.lock_timeout'))->toBe(Locks::timeout())
        ->and(ResourceLockPlugin::get()->getLockTimeout())->toBe(Locks::timeout());
});

it('runs a presence heartbeat with a timeout sized against it', function () {
    $plugin = ResourceLockPlugin::get();

    expect($plugin->shouldUsePollingToDetectPresence())->toBeTrue()
        ->and($plugin->getPresencePollingInterval())->toBeLessThan($plugin->getLockTimeout())
        // Modal, not read-only: a blocked editor gets the blocking notice.
        ->and($plugin->shouldUseReadOnlyMode())->toBeFalse();
});

it('hides the cross-tenant lock manager from the navigation', function () {
    expect(ResourceLockPlugin::get()->shouldRegisterNavigation())->toBeFalse()
        ->and(LockResource::canViewAny())->toBeTrue(); // superadmin
});

it('keeps the lock manager and take-over behind their gates', function () {
    $superadmin = User::where('email', 'admin@example.test')->firstOrFail();
    $tenantAdmin = User::where('email', 'admin-a@example.test')->firstOrFail();
    $editor = User::where('email', 'editor-a@example.test')->firstOrFail();

    expect(Gate::forUser($superadmin)->allows('cms.manage-locks'))->toBeTrue()
        ->and(Gate::forUser($tenantAdmin)->allows('cms.manage-locks'))->toBeFalse()
        ->and(Gate::forUser($tenantAdmin)->allows('cms.take-over-lock'))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('cms.take-over-lock'))->toBeFalse();
});

it('renders the presence observer the plugin injects into every panel page', function () {
    Livewire::test(ResourceLockObserver::class)
        ->assertOk()
        // The take-over button is gated for everyone but admins; the seeded
        // superadmin passes cms.take-over-lock.
        ->assertSet('isAllowedToUnlock', true);
});

it('points the menu builder at the lock-aware edit page', function () {
    expect(FilamentMenuBuilderPlugin::get()->getResource())->toBe(MenuResource::class)
        ->and(MenuResource::getPages()['edit']->getPage())->toBe(EditMenu::class);
});
