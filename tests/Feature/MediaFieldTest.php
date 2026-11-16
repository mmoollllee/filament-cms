<?php

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Forms\MediaField;
use Mmoollllee\Cms\Filament\Pages\Tenancy\EditTenantProfilePage;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;

/*
 * MediaField is how CMS forms pick a library file. It takes no key the
 * Mediathek's delete protection would not read — a file picked there could be
 * deleted while a page shows it — and it saves no file that is gone.
 */

beforeEach(function () {
    Storage::fake('public');
});

it('refuses a picker under a key the Mediathek does not read', function () {
    expect(fn () => MediaField::image('hero'))
        ->toThrow(LogicException::class, "Cms::registerMediaReferenceKeys(['hero'])");
});

it('takes a picker named like a media field, or under a key the app registered', function () {
    expect(MediaField::image('hero_image'))->toBeInstanceOf(MediaPicker::class)
        ->and(MediaField::document('payload.datenblatt'))->toBeInstanceOf(MediaPicker::class)
        ->and(MediaField::media('galerie'))->toBeInstanceOf(MediaPicker::class);

    Cms::registerMediaReferenceKeys(['hero']);

    expect(MediaField::image('hero'))->toBeInstanceOf(MediaPicker::class);
});

it('reports such a picker in production instead of breaking the page, once', function () {
    Exceptions::fake();
    app()->detectEnvironment(fn () => 'production');

    expect(MediaField::image('teaser'))->toBeInstanceOf(MediaPicker::class);

    MediaField::image('teaser');

    Exceptions::assertReported(fn (LogicException $exception): bool => str_contains($exception->getMessage(), '[teaser]'));
    Exceptions::assertReportedCount(1);
});

it('leaves the classic upload alone: it stores paths, not library items', function () {
    Cms::disableMediaLibrary();

    expect(MediaField::image('hero'))->toBeInstanceOf(FileUpload::class);
});

it('drops a file deleted long ago from the form, so the record saves as the form shows it', function () {
    $tenant = actingAsMarketingPanelAdmin();
    $tenant->update(['logo_path' => '999999']);

    Livewire::test(EditTenantProfilePage::class)
        ->assertFormSet(['logo_path' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->fresh()->logo_path)->toBeNull();
});

it('refuses to save a file deleted after the form was opened', function () {
    $tenant = actingAsMarketingPanelAdmin();
    $logo = makeLibraryImage($tenant, 'fixtures/logo.png');

    $form = Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['logo_path' => (string) $logo->getKey()]);

    // Not saved anywhere yet, so the Mediathek lets it go.
    $logo->delete();

    $form->call('save')
        ->assertHasFormErrors(['logo_path']);

    expect($tenant->fresh()->logo_path)->not->toBe((string) $logo->getKey());
});

it('saves a file that is still there', function () {
    $tenant = actingAsMarketingPanelAdmin();
    $logo = makeLibraryImage($tenant, 'fixtures/logo.png');

    Livewire::test(EditTenantProfilePage::class)
        ->fillForm(['logo_path' => (string) $logo->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((string) $tenant->fresh()->logo_path)->toBe((string) $logo->getKey());
});
