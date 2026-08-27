<?php

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\HasFileAttachmentProvider;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\RichEditor\MediaLibraryAttachmentPlugin;
use Mmoollllee\Cms\Filament\RichEditor\Renderer;
use Mmoollllee\Cms\Support\Media\MediaFolders;
use Mmoollllee\Cms\Support\Media\MediaLibraryFileAttachmentProvider;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Filament\Forms\Components\RichEditor\Plugins\FilePlugin;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Workbench\App\Filament\Pages\Dashboard;
use Workbench\App\Models\Tenant;

/*
 * An image pasted into a paragraph and an image chosen through a MediaPicker
 * used to have different fates: the first landed on a bare disk path outside
 * the library, the second became a managed item. These tests pin the provider
 * that makes both the same thing — and, just as importantly, that switching it
 * on does not break the path-shaped identifiers older content already holds.
 */

beforeEach(function () {
    Storage::fake('public');
    // Conversions would run inline against the faked disk; the provider's
    // contract is where the file lands, not what is derived from it.
    Queue::fake();
});

/**
 * A Livewire temporary upload, which is what Filament hands the provider.
 * Built on the real livewire-tmp disk so `getRealPath()` resolves the way it
 * does in a request.
 */
function temporaryUpload(string $name = 'photo.png'): TemporaryUploadedFile
{
    // Livewire swaps its upload disk for `tmp-for-tests` under
    // runningUnitTests() and resolves every temporary file below its own
    // `livewire-tmp` directory. The original file name is BASE64-EMBEDDED in
    // the stored name — build it any other way and getClientOriginalName()
    // returns binary noise, which is not how a real upload behaves.
    $disk = FileUploadConfiguration::disk();
    Storage::fake($disk);

    $file = UploadedFile::fake()->image($name, 40, 30);
    $hashName = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded($file);
    $file->storeAs(FileUploadConfiguration::directory(), $hashName, ['disk' => $disk]);

    return TemporaryUploadedFile::createFromLivewire($hashName);
}

it('stores an editor upload as a media library item', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $id = MediaLibraryFileAttachmentProvider::make()->saveUploadedFileAttachment(temporaryUpload());

    expect($id)->toBeInt();

    $item = Cms::mediaItemModel()::query()->find($id);

    expect($item)->not->toBeNull()
        ->and($item->tenant_id)->toBe($tenant->getKey())
        // Editor uploads belong to a page, so they file under Seiten.
        ->and($item->folder_id)->toBe(MediaFolders::ensure(MediaFolders::PAGES, $tenant)?->getKey())
        ->and(Media::query()->where('model_id', $id)->exists())->toBeTrue();
});

it('returns the library url for a stored item id', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $provider = MediaLibraryFileAttachmentProvider::make();
    $id = $provider->saveUploadedFileAttachment(temporaryUpload());

    expect($provider->getFileAttachmentUrl($id))->toContain("/{$id}/");
});

it('still resolves a path-shaped identifier from older content', function () {
    // What the default provider (and the WordPress import) left behind. Turning
    // this provider on must not blank those images out.
    Storage::disk('public')->put('2020/01/legacy.png', 'x');

    expect(MediaLibraryFileAttachmentProvider::make()->getFileAttachmentUrl('2020/01/legacy.png'))
        ->toContain('2020/01/legacy.png');
});

it('does not delete items dropped from one editor', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $provider = MediaLibraryFileAttachmentProvider::make();
    $id = $provider->saveUploadedFileAttachment(temporaryUpload());

    // The same item may sit on ten pages; removing it from one paragraph is
    // not a reason to delete the file everywhere.
    $provider->cleanUpFileAttachments([]);

    expect(Cms::mediaItemModel()::query()->find($id))->not->toBeNull();
});

it('does not require a saved record before accepting an upload', function () {
    expect(MediaLibraryFileAttachmentProvider::make()->isExistingRecordRequiredToSaveNewFileAttachments())
        ->toBeFalse();
});

it('leaves visibility to the library disk', function () {
    expect(MediaLibraryFileAttachmentProvider::make()->getDefaultFileAttachmentVisibility())
        ->toBeNull();
});

it('carries the provider on a plugin, which is what both ends read', function () {
    $plugin = MediaLibraryAttachmentPlugin::make();

    // RichContentAttribute consults the plugin when saving an upload,
    // RichContentRenderer when resolving a data-id back to a URL.
    expect($plugin)->toBeInstanceOf(HasFileAttachmentProvider::class)
        ->and($plugin->getFileAttachmentProvider())
        ->toBeInstanceOf(MediaLibraryFileAttachmentProvider::class);
});

it('adds nothing to the editor beyond the provider', function () {
    $plugin = MediaLibraryAttachmentPlugin::make();

    expect($plugin->getEditorTools())->toBe([])
        ->and($plugin->getEditorActions())->toBe([])
        ->and($plugin->getTipTapPhpExtensions())->toBe([])
        ->and($plugin->getTipTapJsExtensions())->toBe([]);
});

it('renders a data-id image through the library', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $id = MediaLibraryFileAttachmentProvider::make()->saveUploadedFileAttachment(temporaryUpload());

    // The editor stores the id; the frontend regenerates src from it. Without
    // the provider on the renderer too, this comes out empty.
    $html = Renderer::make('<p><img data-id="'.$id.'"></p>')->toUnsafeHtml();

    expect($html)->toContain("/{$id}/");
});

/*
 * The editor and the renderer find the provider by different routes, and only
 * the renderer's is the plugin. `RichEditor::getFileAttachmentProvider()` reads
 * it off the model's registered rich-content attribute — which CMS content
 * never has, because its rich text lives inside a `blocks` builder. Relying on
 * the plugin alone left the editor with no provider at all: every id failed the
 * disk lookup and rendered as its own bare id, so images 404ed in the panel
 * while being perfectly fine on the site.
 */

it('resolves an item id in the panel editor', function () {
    $tenant = actingAsMarketingPanelAdmin();

    $id = MediaLibraryFileAttachmentProvider::make()->saveUploadedFileAttachment(temporaryUpload());

    $editor = RichEditor::make('content')->container(Schema::make());

    expect($editor->getFileAttachmentUrl($id))
        ->not->toBeNull()
        ->toContain("/{$id}/");
});

it('routes an editor upload into the library', function () {
    $tenant = actingAsMarketingPanelAdmin();

    // A real Livewire host: saveUploadedFileAttachmentUsing() runs through
    // evaluate(), which needs one.
    $editor = RichEditor::make('content')->container(Schema::make(app(Dashboard::class)));

    $id = $editor->saveUploadedFileAttachment(temporaryUpload());

    expect($id)->toBeInt()
        ->and(Cms::mediaItemModel()::query()->find($id))->not->toBeNull();
});

/*
 * Guards added after review. A `data-id` is client-controlled — the editor ships
 * a source-code tab — and a silent null is worse than a failure, because
 * Filament writes it straight into the node and the image is gone for good.
 */

it('refuses an item id belonging to another tenant', function () {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    app(CurrentTenant::class)->set($theirs);
    $theirId = MediaLibraryFileAttachmentProvider::make()->saveUploadedFileAttachment(temporaryUpload());

    app(CurrentTenant::class)->set($mine);

    // Typed into the HTML tab by an editor on another site.
    expect(MediaLibraryFileAttachmentProvider::make()->getFileAttachmentUrl($theirId))->toBeNull();
});

it('refuses any item id when there is no tenant to scope against', function () {
    app(CurrentTenant::class)->forget();

    expect(MediaLibraryFileAttachmentProvider::make()->getFileAttachmentUrl(1))->toBeNull();
});

it('fails loudly rather than destroying an upload without a tenant', function () {
    app(CurrentTenant::class)->forget();

    // Returning null would have Filament write null into the node id and src;
    // the next dehydration drops the node and the image is unrecoverable.
    expect(fn () => MediaLibraryFileAttachmentProvider::make()->saveUploadedFileAttachment(temporaryUpload()))
        ->toThrow(RuntimeException::class);
});

it('leaves no invisible item row when storing the file fails', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $upload = temporaryUpload();

    // The file disappears between the row being created and the media being
    // attached. Without a transaction the row survives, and the `has_media`
    // global scope then hides it from every query in the application.
    Storage::disk(FileUploadConfiguration::disk())->deleteDirectory(FileUploadConfiguration::directory());

    try {
        MediaLibraryFileAttachmentProvider::make()->saveUploadedFileAttachment($upload);
    } catch (Throwable) {
        // The failure is the point; what matters is what it left behind.
    }

    expect(DB::table('filament_media_library')->count())->toBe(0);
});

/**
 * Exactly what the Mediathek picker stores in a node: the driver's FileData key
 * — `media-library-item:{id}`, NOT the bare id — encrypted and base64-encoded.
 *
 * Getting this shape wrong makes the tests below pass against a value
 * production never produces: `encryptKeyHash((string) $item->getKey())` yields
 * a decryptable `"42"`, which is numeric and so takes the guarded path, while
 * the real key is not.
 */
function pickedIdentifier(MediaLibraryItem $item): string
{
    return FileData::encryptKeyHash('media-library-item:'.$item->getKey());
}

/*
 * The picker and the upload both write into the same `data-id` attribute, in
 * different shapes: an upload stores a plain item id, the picker stores the key
 * AES-encrypted and base64-encoded, optionally suffixed with a chosen
 * conversion. Read as-is the second shape is neither numeric nor a path, so it
 * would slip past the tenant check AND resolve to /storage/<base64 blob>.
 */

it('resolves an id written by the Mediathek picker', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $item = makeLibraryImage($tenant);
    $picked = pickedIdentifier($item);

    $url = MediaLibraryFileAttachmentProvider::make()->getFileAttachmentUrl($picked);

    expect($url)->toContain('/'.$item->getKey().'/')
        // The encoded hash itself must not survive into the URL.
        ->and($url)->not->toContain($picked);
});

it('honours a conversion chosen in the picker', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $item = makeLibraryImage($tenant);
    $composite = FilePlugin::getCompositeId(pickedIdentifier($item), ['thumb']);

    // No thumb file exists here, so the resolver falls back to the original —
    // what matters is that the suffix is parsed off rather than pasted into the
    // URL.
    expect(MediaLibraryFileAttachmentProvider::make()->getFileAttachmentUrl($composite))
        ->toContain('/'.$item->getKey().'/')
        ->and(MediaLibraryFileAttachmentProvider::make()->getFileAttachmentUrl($composite))
        ->not->toContain('|');
});

it('still scopes a picked id to the current tenant', function () {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    app(CurrentTenant::class)->set($theirs);
    $theirItem = makeLibraryImage($theirs);

    app(CurrentTenant::class)->set($mine);

    // The encoded shape must not become a way around the check.
    expect(MediaLibraryFileAttachmentProvider::make()->getFileAttachmentUrl(pickedIdentifier($theirItem)))
        ->toBeNull();
});

it('leaves a legacy path untouched by the picker decoding', function () {
    Storage::disk('public')->put('2020/01/legacy.png', 'x');

    expect(MediaLibraryFileAttachmentProvider::make()->getFileAttachmentUrl('2020/01/legacy.png'))
        ->toContain('2020/01/legacy.png');
});
