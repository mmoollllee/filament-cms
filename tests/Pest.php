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
