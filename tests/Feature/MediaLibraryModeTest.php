<?php

use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Forms\MediaField;
use Mmoollllee\Cms\Policies\MediaFolderPolicy;
use Mmoollllee\Cms\Policies\MediaItemPolicy;
use Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriver;
use Mmoollllee\Cms\Support\Media\MediaFolders;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

/*
 * The media library is an OPTIONAL integration: the plugin is a require-dev /
 * suggest dependency, so the package must work in both modes. These tests pin
 * the capability gate, the MediaField dual-mode factory, the default folder
 * provisioning and the tenancy rules (driver + policies).
 */

it('is enabled by default in the workbench and can be opted out', function () {
    expect(MediaLibrary::installed())->toBeTrue()
        ->and(MediaLibrary::enabled())->toBeTrue();

    Cms::disableMediaLibrary();

    expect(MediaLibrary::installed())->toBeTrue()
        ->and(MediaLibrary::enabled())->toBeFalse();
});

it('defaults the driver to the extensions variant when the extensions package is installed', function () {
    // require-dev in this repo — the trait-carrying subclass is picked
    // automatically; installs without the package get the base driver.
    expect(MediaLibrary::extensionsInstalled())->toBeTrue()
        ->and(Cms::mediaDriver())->toBe(\Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriverWithExtensions::class)
        ->and(is_subclass_of(Cms::mediaDriver(), CmsMediaLibraryDriver::class))->toBeTrue();

    Cms::useMediaDriver(CmsMediaLibraryDriver::class);

    expect(Cms::mediaDriver())->toBe(CmsMediaLibraryDriver::class);
});

it('builds a MediaPicker when the library is enabled', function () {
    expect(MediaField::image('logo_path'))->toBeInstanceOf(MediaPicker::class)
        ->and(MediaField::media('media_path'))->toBeInstanceOf(MediaPicker::class)
        ->and(MediaField::document('file_path'))->toBeInstanceOf(MediaPicker::class);
});

it('falls back to a classic tenant-scoped FileUpload when disabled', function () {
    Cms::disableMediaLibrary();

    $field = MediaField::image('logo_path', legacyDirectory: 'tenants/demo/branding');

    expect($field)->toBeInstanceOf(FileUpload::class)
        ->and($field->getDiskName())->toBe('public')
        ->and($field->getDirectory())->toBe('tenants/demo/branding');
});

it('provisions the default folders lazily per tenant and reuses them', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $folder = MediaFolders::pages($tenant);
    $again = MediaFolders::pages($tenant);
    $foreign = MediaFolders::pages($other);

    expect($folder)->toBeInstanceOf(MediaLibraryFolder::class)
        ->and($folder->name)->toBe('Seiten')
        ->and($folder->tenant_id)->toBe($tenant->getKey())
        ->and($again->getKey())->toBe($folder->getKey())
        ->and($foreign->getKey())->not->toBe($folder->getKey())
        ->and(MediaLibraryFolder::query()->count())->toBe(2);
});

it('respects app-configured folder names and maps legacy segments', function () {
    Cms::useMediaFolderNames([
        MediaFolders::BRANDING => 'Marke',
        MediaFolders::PAGES => 'Inhalte',
        MediaFolders::DOCUMENTS => 'Downloads',
    ]);

    $tenant = Tenant::factory()->create();

    expect(MediaFolders::branding($tenant)->name)->toBe('Marke')
        ->and(MediaFolders::keyForLegacySegment('branding'))->toBe(MediaFolders::BRANDING)
        ->and(MediaFolders::keyForLegacySegment('seo'))->toBe(MediaFolders::BRANDING)
        ->and(MediaFolders::keyForLegacySegment('hero'))->toBe(MediaFolders::PAGES)
        ->and(MediaFolders::keyForLegacySegment('content-blocks'))->toBe(MediaFolders::PAGES);
});

it('returns no folder without a tenant context or with the library disabled', function () {
    expect(MediaFolders::pages())->toBeNull();

    Cms::disableMediaLibrary();

    expect(MediaFolders::pages(Tenant::factory()->create()))->toBeNull();
});

it('activates driver tenancy automatically from the Filament tenant', function () {
    Filament::setCurrentPanel(Filament::getPanel('panel'));
    $driver = app(CmsMediaLibraryDriver::class);

    expect($driver->hasTenancy())->toBeFalse();

    $this->actingAs(User::factory()->create());
    Filament::setTenant(Tenant::factory()->create());

    expect($driver->hasTenancy())->toBeTrue();
});

it('lets tenant members manage only their tenant media, superadmins everything', function () {
    $tenant = Tenant::factory()->create();
    $foreignTenant = Tenant::factory()->create();

    $member = User::factory()->create();
    $tenant->users()->attach($member, ['role' => 'admin']);
    $superadmin = User::factory()->superadmin()->create();

    $item = MediaLibraryItem::query()->create([
        'tenant_type' => $tenant->getMorphClass(),
        'tenant_id' => $tenant->getKey(),
    ]);
    $foreignItem = MediaLibraryItem::query()->create([
        'tenant_type' => $foreignTenant->getMorphClass(),
        'tenant_id' => $foreignTenant->getKey(),
    ]);

    $policy = app(MediaItemPolicy::class);

    expect($policy->before($superadmin))->toBeTrue()
        ->and($policy->before($member))->toBeNull()
        ->and($policy->update($member, $item))->toBeTrue()
        ->and($policy->update($member, $foreignItem))->toBeFalse()
        ->and($policy->delete($member, $foreignItem))->toBeFalse();
});

it('denies root folder creation without a Filament tenant context', function () {
    $tenant = Tenant::factory()->create();
    $member = User::factory()->create();
    $tenant->users()->attach($member, ['role' => 'admin']);

    $policy = app(MediaFolderPolicy::class);
    Filament::setCurrentPanel(Filament::getPanel('panel'));

    // No Filament tenant: a new root folder would be stamped `tenant NULL`
    // and be invisible to its creator — must be denied.
    expect($policy->create($member, null))->toBeFalse();

    $this->actingAs($member);
    Filament::setTenant($tenant);
    app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->set($tenant);

    expect($policy->create($member, null))->toBeTrue();
});
