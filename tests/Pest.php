<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Mmoollllee\Cms\Tests\TestCase;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * Valid PNG bytes generated via GD — the same extension conversions run on,
 * so media fixtures behave like real uploads without binary blobs in the repo.
 */
function cmsTestPngBytes(int $size = 20): string
{
    if (! extension_loaded('gd')) {
        test()->markTestSkipped('The media fixtures require the GD extension (spatie conversions run on it too).');
    }

    $image = imagecreatetruecolor($size, $size);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A media-library item holding the given bytes for the given tenant — mirrors
 * the import command's write path. Remember the `has_media` global scope:
 * items are only queryable once a Spatie media row exists, which this helper
 * guarantees.
 */
function attachLibraryFile(Tenant $tenant, string $sourcePath, string $bytes, array $attributes = [], bool $preserveOriginal = false): \RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem
{
    \Illuminate\Support\Facades\Storage::disk('public')->put($sourcePath, $bytes);

    $item = \Mmoollllee\Cms\Cms::mediaItemModel()::query()->create([
        'tenant_type' => $tenant->getMorphClass(),
        'tenant_id' => $tenant->getKey(),
        ...$attributes,
    ]);

    $adder = $item->driver(app(\Mmoollllee\Cms\Cms::mediaDriver()))->addMediaFromDisk($sourcePath, 'public');

    if ($preserveOriginal) {
        $adder->preservingOriginal();
    }

    $adder
        ->usingName(pathinfo($sourcePath, PATHINFO_FILENAME))
        ->toMediaCollection($item->getMediaLibraryCollectionName());

    return $item->refresh();
}

/** A media-library item with an attached (real) PNG. */
function makeLibraryImage(Tenant $tenant, string $sourcePath = 'fixtures/pic.png', array $attributes = []): \RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem
{
    return attachLibraryFile($tenant, $sourcePath, cmsTestPngBytes(), $attributes, preserveOriginal: true);
}

/**
 * A media-library item holding an arbitrary file — the bytes decide the MIME
 * type the library records (see {@see cmsVideoBytes()} for videos that need no
 * real encoding).
 */
function makeLibraryFile(Tenant $tenant, string $fileName, string $bytes, array $attributes = []): \RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem
{
    return attachLibraryFile($tenant, 'fixtures/'.$fileName, $bytes, $attributes);
}

/** The item's file as it is now — a conversion or a move replaces the row. */
function itemMedia($item): \Spatie\MediaLibrary\MediaCollections\Models\Media
{
    return $item->refresh()->getFirstMedia($item->getMediaLibraryCollectionName());
}

/** @param  array<int, array<string, mixed>>  $blocks */
function sectionWith(array $blocks): array
{
    return [['type' => 'section', 'data' => ['blocks' => $blocks]]];
}

function mediaBlock(mixed $ref): array
{
    return ['type' => 'media', 'data' => ['media_path' => $ref, 'columns' => 3]];
}

/** A page "Über uns" showing the item in a media block — published, unless the attributes say otherwise. */
function pageShowing(Tenant $tenant, $item, array $attributes = []): \Workbench\App\Models\Content
{
    return \Workbench\App\Models\Content::factory()->for($tenant)->create([
        'title' => 'Über uns',
        'blocks' => sectionWith([mediaBlock($item->getKey())]),
        ...$attributes,
    ]);
}

/**
 * What a picker stores for an item: its key, encrypted — the real key is not —
 * with a chosen conversion appended the way the rich editor writes it.
 */
function pickerKey(int|\RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem $item, ?string $conversion = null): string
{
    $id = $item instanceof \RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem ? $item->getKey() : $item;

    return \RalphJSmit\Filament\Explore\Filament\Forms\Components\RichEditor\Plugins\FilePlugin::getCompositeId(
        \RalphJSmit\Filament\Explore\Data\FileData::encryptKeyHash('media-library-item:'.$id),
        array_filter([$conversion]),
    );
}

/**
 * Pattern for a media row's library directory inside a URL or path: `/{id}/`,
 * or `/{id}-{token}/` where the private media library gave it a random one.
 */
function libraryDirectoryPattern(int|string $mediaId): string
{
    return '#/'.$mediaId.'(?:-[A-Za-z0-9]{16,64})?/#';
}

/**
 * The few bytes libmagic needs to call a file a video: an ISO-BMFF `ftyp` box
 * (QuickTime or MP4 brand) or an EBML header (WebM). Nothing can play them —
 * nothing in the tests tries to.
 */
function cmsVideoBytes(string $type = 'mov', int $padding = 0): string
{
    $bytes = match ($type) {
        'mov' => "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  \x00\x00\x00\x08free",
        'mp4' => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free",
        'webm' => "\x1aE\xdf\xa3\x9fB\x86\x81\x01B\xf7\x81\x01B\xf2\x81\x04B\xf3\x81\x08B\x82\x84webm",
    };

    return $bytes.str_repeat("\x00", $padding);
}

/**
 * The TipTap editor the RichEditor round-trips its state through: exactly what
 * RichEditor::getTipTapEditor() builds (Filament's renderer plus the given
 * plugins), but without a schema container to mount the component in.
 *
 * @param  array<\Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin>  $plugins
 */
function tipTapEditorWithPlugins(array $plugins): \Tiptap\Editor
{
    return \Filament\Forms\Components\RichEditor\RichContentRenderer::make()
        ->plugins($plugins)
        ->getEditor();
}

/**
 * A tenant's `header` menu holding a single item.
 *
 * Lives here rather than in a test file because Pest loads every test into one
 * process: a second file declaring its own copy is a fatal redeclare.
 *
 * @param  array{title?: string, url?: string, target?: string, rel?: string, classes?: string, icon?: string}  $itemAttributes
 */
function makeHeaderMenu(Tenant $tenant, array $itemAttributes = []): \Mmoollllee\Cms\Models\Menu
{
    $menu = \Mmoollllee\Cms\Models\Menu::create([
        'name' => 'Header',
        'tenant_id' => $tenant->id,
        'is_visible' => true,
    ]);

    $menu->locations()->create(['location' => 'header', 'tenant_id' => $tenant->id]);
    $menu->menuItems()->create(['title' => 'Start', 'url' => '/', 'order' => 0, ...$itemAttributes]);

    return $menu;
}

/**
 * Shared panel-test bootstrap: seeds the demo, selects the panel, signs in the
 * seeded superadmin and primes the marketing tenant. Returns that tenant.
 */
function actingAsMarketingPanelAdmin(): Tenant
{
    return actingAsMarketingPanelUser('admin@example.test');
}

/**
 * Same bootstrap for any seeded account — e.g. the tenant Editor
 * (editor-a@example.test) — to pin role-dependent panel behaviour.
 */
function actingAsMarketingPanelUser(string $email): Tenant
{
    test()->seed(DatabaseSeeder::class);

    return switchToMarketingPanelUser($email);
}

/**
 * Switch the acting account WITHOUT re-seeding — for tests that need a second
 * role after the bootstrap already ran. The seeder is not idempotent (bare
 * create()/factory() calls), so calling actingAsMarketingPanelUser() a second
 * time inside a test doubles the whole demo dataset.
 */
function switchToMarketingPanelUser(string $email): Tenant
{
    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $tenant = Tenant::where('site_key', 'marketing')->firstOrFail();

    test()->actingAs(User::where('email', $email)->firstOrFail());
    Filament::setTenant($tenant);
    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

/**
 * Minimal Umami credentials so the optional filami integration is live
 * (Filami::apiConfigured()). Lives here rather than in a test file so a second
 * file using it cannot redeclare it.
 */
function filamiConfigured(): void
{
    config()->set('filami.url', 'https://a.example.test');
    config()->set('filami.username', 'provisioner');
    config()->set('filami.password', 'secret');
}

/**
 * A concrete, UNNAMED host exposing AbstractTenantAwareForm's protected
 * scaffolding for assertion. Lives here rather than in a test file so a second
 * file using it cannot redeclare it — Pest loads every test into one process.
 */
function tenantFormHost(): object
{
    return new class extends \Mmoollllee\Cms\Support\Livewire\AbstractTenantAwareForm
    {
        public function submit(): void {}

        public function tripped(): bool
        {
            return $this->trippedHoneypot();
        }

        public function key(string $prefix): string
        {
            return $this->rateLimitKey($prefix);
        }

        public function recipient(?string $override): string
        {
            return $this->resolveContactRecipient($override);
        }

        public function stamp(): string
        {
            return $this->submittedAt();
        }

        public function captureUrl(?string $sourceUrl): ?string
        {
            $this->captureSourceUrl($sourceUrl);

            return $this->sourceUrl;
        }
    };
}

/**
 * The panel pages BasePanelProvider registers. Protected there, because a
 * panel's page list is configuration rather than API.
 *
 * @return array<int, class-string>
 */
function panelPages(): array
{
    $provider = new class(app()) extends \Mmoollllee\Cms\Filament\Providers\BasePanelProvider
    {
        public function pages(): array
        {
            return $this->panelPages();
        }
    };

    return $provider->pages();
}

/**
 * A RichEditor as the panel actually resolves it: configureUsing() applied,
 * plugin toolbar modifications folded in, on a real Livewire host so that the
 * `...Using()` closures — which run through evaluate() — have one.
 *
 * Lives here rather than in a test file because Pest loads every test into one
 * process: a second file declaring its own copy is a fatal redeclare.
 */
function configuredRichEditor(): \Filament\Forms\Components\RichEditor
{
    return \Filament\Forms\Components\RichEditor::make('content')
        ->container(\Filament\Schemas\Schema::make(app(\Workbench\App\Filament\Pages\Dashboard::class)));
}
