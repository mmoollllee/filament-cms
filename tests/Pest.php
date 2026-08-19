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
 * A media-library item with an attached (real) PNG for the given tenant —
 * mirrors the import command's write path. Remember the `has_media` global
 * scope: items are only queryable once a Spatie media row exists, which this
 * helper guarantees.
 */
function makeLibraryImage(Tenant $tenant, string $sourcePath = 'fixtures/pic.png', array $attributes = []): \RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem
{
    \Illuminate\Support\Facades\Storage::disk('public')->put($sourcePath, cmsTestPngBytes());

    $item = \Mmoollllee\Cms\Cms::mediaItemModel()::query()->create([
        'tenant_type' => $tenant->getMorphClass(),
        'tenant_id' => $tenant->getKey(),
        ...$attributes,
    ]);

    $item
        ->driver(app(\Mmoollllee\Cms\Cms::mediaDriver()))
        ->addMediaFromDisk($sourcePath, 'public')
        ->preservingOriginal()
        ->usingName(pathinfo($sourcePath, PATHINFO_FILENAME))
        ->toMediaCollection($item->getMediaLibraryCollectionName());

    return $item->refresh();
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
