<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * cms:media:import migrates real-world legacy data (validated against
 * pernes-hebesysteme.de + muench-tiefbau.de): the scan is VALUE-based, so
 * WordPress-era keys (payload.galerie arrays, hero.thumbnail, feature-card
 * image_path) migrate exactly like the newer media_path blocks — and the
 * draft stash is rewritten too, so an applied draft cannot resurrect paths.
 */

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

function seedLegacyMediaFixture(): array
{
    $tenant = Tenant::factory()->create(['logo_path' => 'branding/demo/logo.png']);

    foreach ([
        'content-blocks/pic.png',
        '2020/01/a.png',
        '2020/01/b.png',
        'branding/demo/logo.png',
        'downloads/prospekt.pdf',
    ] as $path) {
        Storage::disk('public')->put($path, cmsTestPngBytes());
    }

    $content = Content::factory()->for($tenant)->create([
        'blocks' => [[
            'type' => 'media',
            'data' => ['media_path' => 'content-blocks/pic.png', 'media_alt' => 'Ein Bild', 'active' => true],
        ]],
        'payload' => [
            'galerie' => ['2020/01/a.png', '2020/01/b.png'],
            'hero' => ['thumbnail' => '2020/01/a.png'],
            'datenblatt' => 'downloads/prospekt.pdf',
        ],
        'meta' => ['og_image_url' => '/storage/2020/01/a.png'],
        'draft' => [
            'data' => ['blocks' => [[
                'type' => 'media',
                'data' => ['media_path' => 'content-blocks/pic.png'],
            ]]],
            'saved_at' => now()->toIso8601String(),
            'saved_by' => 1,
        ],
    ]);

    return [$tenant, $content];
}

it('imports every referenced file once and rewrites all reference shapes to item ids', function () {
    [$tenant, $content] = seedLegacyMediaFixture();

    $this->artisan('cms:media:import')->assertSuccessful();

    $content->refresh();
    $tenant->refresh();

    $blockRef = data_get($content->blocks, '0.data.media_path');
    $galerie = data_get($content->payload, 'galerie');
    $thumbnail = data_get($content->payload, 'hero.thumbnail');
    $draftRef = data_get($content->draft, 'data.blocks.0.data.media_path');

    expect($blockRef)->toBeInt()
        ->and($galerie)->each->toBeInt()
        ->and($thumbnail)->toBeInt()
        // Same file referenced twice → same item.
        ->and($thumbnail)->toBe($galerie[0])
        ->and($draftRef)->toBe($blockRef)
        // Root-anchored legacy URLs stay untouched (resolver keeps serving them).
        ->and(data_get($content->meta, 'og_image_url'))->toBe('/storage/2020/01/a.png')
        ->and($tenant->logo_path)->toBeNumeric();

    // 5 unique files → 5 items, alt text prefilled from the block override.
    expect(Cms::mediaItemModel()::query()->count())->toBe(5)
        ->and(Cms::mediaItemModel()::query()->find($blockRef)->alt_text)->toBe('Ein Bild')
        ->and(Cms::mediaItemModel()::query()->find($blockRef)->tenant_id)->toBe($tenant->getKey());

    // Folder mapping: branding column → Branding, blocks → Seiten, pdf → Dokumente.
    $folderNames = MediaLibraryFolder::query()->pluck('name');
    expect($folderNames)->toContain('Branding')->toContain('Seiten')->toContain('Dokumente');

    $logoItem = Cms::mediaItemModel()::query()->find((int) $tenant->logo_path);
    expect($logoItem->folder->name)->toBe('Branding');

    $pdfItem = Cms::mediaItemModel()::query()->find(data_get($content->payload, 'datenblatt'));
    expect($pdfItem->folder->name)->toBe('Dokumente');

    // Originals stay on disk (rollback safety).
    expect(Storage::disk('public')->exists('content-blocks/pic.png'))->toBeTrue();
});

it('is idempotent — a second run imports nothing new and changes nothing', function () {
    seedLegacyMediaFixture();

    $this->artisan('cms:media:import')->assertSuccessful();
    $countAfterFirst = Cms::mediaItemModel()::query()->count();
    $blocksAfterFirst = Content::query()->first()->blocks;

    $this->artisan('cms:media:import')->assertSuccessful();

    expect(Cms::mediaItemModel()::query()->count())->toBe($countAfterFirst)
        ->and(Content::query()->first()->blocks)->toBe($blocksAfterFirst);
});

it('writes nothing in dry-run mode', function () {
    [$tenant, $content] = seedLegacyMediaFixture();

    $this->artisan('cms:media:import --dry-run')->assertSuccessful();

    expect(Cms::mediaItemModel()::query()->count())->toBe(0)
        ->and(data_get($content->refresh()->blocks, '0.data.media_path'))->toBe('content-blocks/pic.png')
        ->and($tenant->refresh()->logo_path)->toBe('branding/demo/logo.png');
});

it('imports unreferenced files only with --all (tenant dirs + single-tenant year folders)', function () {
    [$tenant] = seedLegacyMediaFixture();
    Storage::disk('public')->put("tenants/{$tenant->site_key}/content-blocks/orphan.png", cmsTestPngBytes());
    Storage::disk('public')->put('2019/05/wp-orphan.png', cmsTestPngBytes());
    Storage::disk('public')->put('livewire-tmp/tmp-upload.png', cmsTestPngBytes());

    $this->artisan('cms:media:import')->assertSuccessful();
    expect(Cms::mediaItemModel()::query()->count())->toBe(5);

    $this->artisan('cms:media:import --all')->assertSuccessful();

    // + orphan.png (tenant dir) + wp-orphan.png (single-tenant year dir);
    // livewire temp uploads are never imported.
    expect(Cms::mediaItemModel()::query()->count())->toBe(7);
});

it('fails cleanly when the media library is unavailable', function () {
    Cms::disableMediaLibrary();

    $this->artisan('cms:media:import')->assertFailed();
});
