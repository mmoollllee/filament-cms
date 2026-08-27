<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Support\AesFixedInitializationVectorCrypt;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * The failure this repairs: v0.17.2/v0.17.3 stored the picker's key hash — the
 * driver key encrypted with APP_KEY — in `data-id`. Pull that database into
 * another environment and the value no longer decrypts, so every picked image
 * resolves to `/storage/<ciphertext>` and is dead in the panel and on the site.
 *
 * The tests below use a hash produced under a DIFFERENT key, which is the shape
 * that actually broke. A hash encrypted under the current key would take the
 * decrypt path and prove nothing about the case that was reported.
 */

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

/** A picker key hash as some OTHER environment would have written it. */
function foreignPickerKeyHash(int $itemId): string
{
    $ours = config('app.key');

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    app()->forgetInstance(AesFixedInitializationVectorCrypt::class);

    $hash = FileData::encryptKeyHash('media-library-item:'.$itemId);

    config()->set('app.key', $ours);
    app()->forgetInstance(AesFixedInitializationVectorCrypt::class);

    return $hash;
}

function contentWithPickedImage(string $dataId, ?string $src = null): array
{
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);

    $src ??= '/storage/'.$item->getKey().'/pic.png';

    $content = Content::factory()->for($tenant)->create([
        'blocks' => [[
            'type' => 'text',
            'data' => ['content' => '<p><img src="'.$src.'" data-id="'.$dataId.'"></p>'],
        ]],
    ]);

    return [$content, $item];
}

it('rewrites a foreign key hash to the item id', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $id = $item->getKey();

    $content = Content::factory()->for($tenant)->create([
        'blocks' => [[
            'type' => 'text',
            'data' => ['content' => '<p><img src="/storage/'.$id.'/pic.png" data-id="'.foreignPickerKeyHash($id).'"></p>'],
        ]],
    ]);

    $this->artisan('cms:media:repair-picker-ids')->assertSuccessful();

    expect($content->fresh()->blocks[0]['data']['content'])
        ->toContain('data-id="'.$id.'"');
});

it('keeps a chosen conversion on the repaired id', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $id = $item->getKey();

    $content = Content::factory()->for($tenant)->create([
        'blocks' => [[
            'type' => 'text',
            'data' => ['content' => '<p><img src="/storage/'.$id.'/conversions/pic-responsive.webp" data-id="'.foreignPickerKeyHash($id).'|responsive"></p>'],
        ]],
    ]);

    $this->artisan('cms:media:repair-picker-ids')->assertSuccessful();

    expect($content->fresh()->blocks[0]['data']['content'])
        ->toContain('data-id="'.$id.'|responsive"');
});

it('leaves an id it cannot resolve alone rather than guessing', function () {
    // No usable src, and a key from another environment: there is no honest way
    // back to an id here, and writing a wrong one would trade a dead image for a
    // silently WRONG one.
    [$content] = contentWithPickedImage(foreignPickerKeyHash(9999), src: 'https://example.test/elsewhere.png');

    $before = $content->blocks[0]['data']['content'];

    $this->artisan('cms:media:repair-picker-ids')->assertSuccessful();

    expect($content->fresh()->blocks[0]['data']['content'])->toBe($before);
});

it('does not trust a src pointing at a missing item', function () {
    [$content] = contentWithPickedImage(foreignPickerKeyHash(4242), src: '/storage/999999/pic.png');

    $before = $content->blocks[0]['data']['content'];

    $this->artisan('cms:media:repair-picker-ids')->assertSuccessful();

    expect($content->fresh()->blocks[0]['data']['content'])->toBe($before);
});

it('leaves ids that are already portable untouched', function () {
    [$content] = contentWithPickedImage('1234');

    $before = $content->blocks[0]['data']['content'];

    $this->artisan('cms:media:repair-picker-ids')->assertSuccessful();

    expect($content->fresh()->blocks[0]['data']['content'])->toBe($before);
});

it('writes nothing under --dry-run', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $id = $item->getKey();

    $content = Content::factory()->for($tenant)->create([
        'blocks' => [[
            'type' => 'text',
            'data' => ['content' => '<p><img src="/storage/'.$id.'/pic.png" data-id="'.foreignPickerKeyHash($id).'"></p>'],
        ]],
    ]);

    $before = $content->blocks[0]['data']['content'];

    $this->artisan('cms:media:repair-picker-ids', ['--dry-run' => true])->assertSuccessful();

    expect($content->fresh()->blocks[0]['data']['content'])->toBe($before);
});

it('reaches rich text nested inside a section block', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $id = $item->getKey();

    // The reported page had its text blocks one level down, inside a section.
    $content = Content::factory()->for($tenant)->create([
        'blocks' => [[
            'type' => 'section',
            'data' => ['blocks' => [[
                'type' => 'text',
                'data' => ['content' => '<p><img src="/storage/'.$id.'/pic.png" data-id="'.foreignPickerKeyHash($id).'"></p>'],
            ]]],
        ]],
    ]);

    $this->artisan('cms:media:repair-picker-ids')->assertSuccessful();

    expect($content->fresh()->blocks[0]['data']['blocks'][0]['data']['content'])
        ->toContain('data-id="'.$id.'"');
});

it('does not leave the repaired page serving from a stale cache', function () {
    // Found the hard way: saveQuietly() skips ContentCacheObserver, which is the
    // sole invalidation for the rememberForever frontend cache. The repair wrote
    // correct ids and the page kept rendering the dead ciphertext — which reads
    // exactly like the command having done nothing at all.
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $id = $item->getKey();

    $content = Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Über Uns',
        'path' => '/ueber-uns',
        'visibility' => 'public',
        'publish_from' => now()->subDay(),
        'blocks' => [[
            'type' => 'text',
            'data' => ['content' => '<p><img src="/storage/'.$id.'/pic.png" data-id="'.foreignPickerKeyHash($id).'"></p>'],
        ]],
    ]);

    $resolver = app(ContentResolver::class);

    // Warm it the way a visitor would.
    $resolver->findByPath($tenant, '/ueber-uns');

    $this->artisan('cms:media:repair-picker-ids')->assertSuccessful();

    expect($resolver->findByPath($tenant, '/ueber-uns')->blocks[0]['data']['content'])
        ->toContain('data-id="'.$id.'"');
});
