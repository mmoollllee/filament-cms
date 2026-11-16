<?php

use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Exceptions\MediaItemInUse;
use Mmoollllee\Cms\Support\Media\MediaDeletionGuard;
use Mmoollllee\Cms\Support\Media\MediaReferences;
use RalphJSmit\Filament\Explore\Filament\Actions\DeleteAction;
use RalphJSmit\Filament\Explore\Filament\Actions\DeleteBulkAction;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver\FileData;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * A file a record still uses cannot be deleted — deleting it used to make it
 * vanish from the live page without a word. The editor sees where it is used
 * instead; nothing is removed, not even the unused neighbours in a folder.
 */

beforeEach(function () {
    Storage::fake('public');
});

function libraryDriver(): \Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriver
{
    return app(Cms::mediaDriver());
}

function fileDataFor($item): FileData
{
    return FileData::fromMediaLibraryItem($item->driver(libraryDriver()), libraryDriver()->getImageGenerators());
}

/** Titles of the notifications sent in this request. */
function sentNotificationTitles(): array
{
    $mailbox = app(\Filament\Notifications\NotificationMailbox::class);
    $mailbox->flush();

    return collect($mailbox->pull())
        ->pluck('title')
        ->all();
}

function folderFor(Tenant $tenant, string $name = 'Ordner', ?MediaLibraryFolder $parent = null): MediaLibraryFolder
{
    return MediaLibraryFolder::query()->create([
        'name' => $name,
        'parent_id' => $parent?->getKey(),
        'tenant_type' => $tenant->getMorphClass(),
        'tenant_id' => $tenant->getKey(),
    ]);
}

it('refuses to delete a used item before a single file is removed', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $path = $item->getItem()->getPathRelativeToRoot();
    pageShowing($tenant, $item);

    expect(fn () => $item->delete())->toThrow(MediaItemInUse::class);

    // The library's own deleting listener — the one that removes the files —
    // must not have run either.
    expect(Cms::mediaItemModel()::query()->whereKey($item->getKey())->exists())->toBeTrue()
        ->and(Media::query()->where('model_id', $item->getKey())->exists())->toBeTrue();

    Storage::disk('public')->assertExists($path);
});

it('also protects a file that only an unapplied draft uses', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $content = Content::factory()->for($tenant)->create();
    $content->stashDraft(['blocks' => [['type' => 'media', 'data' => ['media_path' => $item->getKey()]]]]);

    expect(fn () => $item->delete())->toThrow(MediaItemInUse::class);
});

it('deletes an unused item with its files', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $path = $item->getItem()->getPathRelativeToRoot();

    $item->delete();

    expect(Cms::mediaItemModel()::query()->whereKey($item->getKey())->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($path);
});

it('lets code that deletes on purpose through', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    pageShowing($tenant, $item);

    MediaDeletionGuard::withoutProtection(fn () => $item->delete());

    expect(Cms::mediaItemModel()::query()->whereKey($item->getKey())->exists())->toBeFalse();
});

it('makes the driver refuse with the list of uses instead of deleting', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/team.png');
    pageShowing($tenant, $item);

    expect(fn () => libraryDriver()->deleteFile(fileDataFor($item)))->toThrow(Halt::class);

    expect(sentNotificationTitles())->toBe(['„team.png“ wird noch verwendet und wurde nicht gelöscht'])
        ->and(Cms::mediaItemModel()::query()->whereKey($item->getKey())->exists())->toBeTrue();
});

it('keeps a whole folder when anything inside it is still used', function () {
    $tenant = Tenant::factory()->create();
    $folder = folderFor($tenant);
    $sub = folderFor($tenant, 'Unterordner', $folder);
    $unused = makeLibraryImage($tenant, 'fixtures/a.png', ['folder_id' => $folder->getKey()]);
    $used = makeLibraryImage($tenant, 'fixtures/b.png', ['folder_id' => $sub->getKey()]);
    pageShowing($tenant, $used);

    $folderData = FileData::fromMediaLibraryFolder($folder->driver(libraryDriver()));

    expect(fn () => libraryDriver()->deleteFile($folderData))->toThrow(Halt::class);

    expect(MediaLibraryFolder::query()->whereKey([$folder->getKey(), $sub->getKey()])->count())->toBe(2)
        ->and(Cms::mediaItemModel()::query()->whereKey([$unused->getKey(), $used->getKey()])->count())->toBe(2);
});

it('deletes a folder whose files nobody uses', function () {
    $tenant = Tenant::factory()->create();
    $folder = folderFor($tenant);
    $item = makeLibraryImage($tenant, 'fixtures/a.png', ['folder_id' => $folder->getKey()]);

    libraryDriver()->deleteFile(FileData::fromMediaLibraryFolder($folder->driver(libraryDriver())));

    expect(MediaLibraryFolder::query()->whereKey($folder->getKey())->exists())->toBeFalse()
        ->and(Cms::mediaItemModel()::query()->whereKey($item->getKey())->exists())->toBeFalse();
});

it('says in the delete dialog where a file is used and offers no delete button', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/team.png');
    pageShowing($tenant, $item);

    $action = DeleteAction::make()->driver(libraryDriver())->file(fileDataFor($item));

    expect($action->getModalHeading())->toBe('„team.png“ wird noch verwendet')
        ->and((string) $action->getModalDescription())->toContain('Über uns', 'veröffentlicht')
        ->and($action->getModalCancelActionLabel())->toBe('Schließen');
});

it('keeps the vendor dialog for a file nobody uses', function () {
    $item = makeLibraryImage(Tenant::factory()->create(), 'fixtures/team.png');

    $action = DeleteAction::make()->driver(libraryDriver())->file(fileDataFor($item));

    expect($action->getModalHeading())->toBe(__('filament-explore::filament/actions.delete_action.modal_heading.file', ['displayName' => 'team']))
        ->and((string) $action->getModalDescription())->toBe(__('filament-explore::filament/actions.delete_action.modal_description.file'));
});

it('lists the used files of a bulk selection in its dialog', function () {
    $tenant = Tenant::factory()->create();
    $used = makeLibraryImage($tenant, 'fixtures/used.png');
    $free = makeLibraryImage($tenant, 'fixtures/free.png');
    pageShowing($tenant, $used);

    $description = MediaDeletionGuard::blockedBy([fileDataFor($used), fileDataFor($free)], libraryDriver());

    expect(array_keys($description))->toBe([(int) $used->getKey()]);

    $action = DeleteBulkAction::make()->driver(libraryDriver())->arguments([
        'bulkSelectedFileKeys' => [fileDataFor($used)->getKeyHash() => true, fileDataFor($free)->getKeyHash() => true],
    ]);

    // Nothing was tried yet: no word of anything not being deleted.
    expect((string) $action->getModalDescription())->toContain('„used.png“ wird noch verwendet', 'Nimm sie aus der Auswahl', 'wo sie verwendet wird', 'Über uns')
        ->not->toContain('free.png', 'nicht gelöscht');
});

it('shows the uses in the file info', function () {
    $tenant = Tenant::factory()->create();
    $used = makeLibraryImage($tenant, 'fixtures/used.png');
    $free = makeLibraryImage($tenant, 'fixtures/free.png');
    pageShowing($tenant, $used, ['title' => 'Kontakt', 'publish_from' => null]);

    // Only the CMS entries — the vendor's own ones need a mounted schema.
    $render = fn ($item): string => collect(libraryDriver()->getFileInfoInformationComponents(fileDataFor($item)))
        ->filter(fn ($component) => in_array($component->getLabel(), ['Verwendet auf', 'Video'], true))
        ->map(fn ($component) => $component->getLabel().': '.collect($component->getDefaultChildComponents())->map(fn ($text) => (string) $text->getContent())->implode(' '))
        ->implode(' | ');

    expect($render($used))->toContain('Verwendet auf', 'Kontakt', 'nicht veröffentlicht')
        ->and($render($free))->toContain('Nirgends');

    expect(MediaReferences::of($used))->not->toBeEmpty();
});
