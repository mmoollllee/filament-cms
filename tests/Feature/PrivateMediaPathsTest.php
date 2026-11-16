<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use Mmoollllee\Cms\Support\Media\PrivateMediaPathGenerator;
use Mmoollllee\Cms\Support\Media\PrivateMediaPaths;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/*
 * The private media library: a file nobody has been shown cannot be found by
 * trying links. New files get an unguessable directory and keep it for good;
 * files stored before move once, by cms:media:secure.
 */

beforeEach(function () {
    Storage::fake('public');
});

function pageLinking(Tenant $tenant, string $html, array $attributes = []): Content
{
    return Content::factory()->for($tenant)->create([
        'blocks' => [['type' => 'section', 'data' => ['blocks' => [['type' => 'text', 'data' => ['content' => $html]]]]]],
        ...$attributes,
    ]);
}

function linkedHtml(Content $page): string
{
    return $page->fresh()->blocks[0]['data']['blocks'][0]['data']['content'];
}

/** A library image stored the way it was before the private library existed. */
function legacyLibraryImage(Tenant $tenant, string $path = 'fixtures/legacy.png')
{
    config(['cms.media.private' => false]);

    try {
        return makeLibraryImage($tenant, $path);
    } finally {
        config(['cms.media.private' => true]);
    }
}

function forgetCustomPathGenerators(): void
{
    (fn () => static::$customPathGenerators = [])->bindTo(null, PathGeneratorFactory::class)();
}

it('stores a new upload under an unguessable directory', function () {
    $item = makeLibraryImage(Tenant::factory()->create(), 'fixtures/plan.png');
    $media = itemMedia($item);
    $token = PrivateMediaPaths::token($media);

    expect($token)->toMatch('/^[A-Za-z0-9]{24}$/')
        ->and($media->getPathRelativeToRoot())->toBe("{$media->getKey()}-{$token}/plan.png")
        ->and(MediaUrlResolver::url($item->getKey()))->toContain("/{$media->getKey()}-{$token}/plan.png");

    Storage::disk('public')->assertExists("{$media->getKey()}-{$token}/plan.png");
    Storage::disk('public')->assertMissing("{$media->getKey()}/plan.png");

    // Derivatives sit below the same directory.
    expect($media->getPathRelativeToRoot('thumb'))->toStartWith("{$media->getKey()}-{$token}/conversions/");
});

it('keeps that directory for good: publishing, taking offline and swapping the image move nothing', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/plan.png');
    $other = makeLibraryImage($tenant, 'fixtures/b.png');
    $path = itemMedia($item)->getPathRelativeToRoot();

    $page = pageShowing($tenant, $item);
    $page->update(['publish_from' => null]);
    $page->update(['publish_from' => now()->subDay(), 'blocks' => [['type' => 'media', 'data' => ['media_path' => $other->getKey()]]]]);
    $page->delete();

    $this->artisan('cms:media:secure')->assertSuccessful();

    expect(itemMedia($item)->getPathRelativeToRoot())->toBe($path);
    Storage::disk('public')->assertExists($path);
});

it('moves the files stored before the private library once, all but those a published page shows', function () {
    $tenant = Tenant::factory()->create();
    $hidden = legacyLibraryImage($tenant, 'fixtures/hidden.png');
    $shown = legacyLibraryImage($tenant, 'fixtures/shown.png');

    expect(PrivateMediaPaths::token(itemMedia($hidden)))->toBeNull()
        ->and(itemMedia($hidden)->getPathRelativeToRoot())->toBe(itemMedia($hidden)->getKey().'/hidden.png');

    $page = pageShowing($tenant, $shown);

    $this->artisan('cms:media:secure', ['--dry-run' => true])->assertSuccessful();
    expect(PrivateMediaPaths::token(itemMedia($hidden)))->toBeNull();

    $this->artisan('cms:media:secure')->assertSuccessful();

    $media = itemMedia($hidden);
    $token = PrivateMediaPaths::token($media);

    expect($token)->not->toBeNull()
        ->and(PrivateMediaPaths::token(itemMedia($shown)))->toBeNull();

    Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    Storage::disk('public')->assertMissing($media->getKey().'/hidden.png');

    // Nothing moves on its own — not even once the page is gone.
    $page->delete();

    expect(PrivateMediaPaths::token(itemMedia($shown)))->toBeNull();

    // The next run takes it, and leaves what has a directory already.
    $this->artisan('cms:media:secure')->assertSuccessful();

    expect(PrivateMediaPaths::token(itemMedia($shown)))->not->toBeNull()
        ->and(PrivateMediaPaths::token(itemMedia($hidden)))->toBe($token);
});

it('moves a file only a scheduled page uses — the page builds its URL once it goes live', function () {
    $tenant = Tenant::factory()->create();
    $item = legacyLibraryImage($tenant, 'fixtures/launch.png');
    $legacyPath = itemMedia($item)->getPathRelativeToRoot();
    pageShowing($tenant, $item, ['publish_from' => now()->addWeek()]);

    $this->artisan('cms:media:secure')->assertSuccessful();

    $media = itemMedia($item);

    expect(PrivateMediaPaths::token($media))->not->toBeNull();

    Storage::disk('public')->assertMissing($legacyPath);
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
});

it('leaves a file a published page links by its URL where it is, so the link keeps working', function () {
    $tenant = Tenant::factory()->create();
    $item = legacyLibraryImage($tenant, 'fixtures/preisliste.png');
    $url = itemMedia($item)->getUrl();
    $page = pageLinking($tenant, '<p><a href="'.$url.'">Preisliste</a></p>');

    $this->artisan('cms:media:secure')->assertSuccessful();

    expect(PrivateMediaPaths::token(itemMedia($item)))->toBeNull()
        ->and(linkedHtml($page))->toContain($url);
});

it('rewrites the links stored as text to a file it moves: page text, draft stash, version, redirect', function () {
    $tenant = Tenant::factory()->create();
    $item = legacyLibraryImage($tenant, 'fixtures/preisliste.png');
    $media = itemMedia($item);
    $url = $media->getUrl();
    $thumb = $media->getUrl('thumb');

    // The old directory also holds a file of an import, which stays put.
    Storage::disk('public')->put($media->getKey().'/01/import.png', 'from the old site');
    $imported = Storage::disk('public')->url($media->getKey().'/01/import.png');

    $draft = pageLinking($tenant, '<p><a href="'.$url.'">Preisliste</a> <img src="'.$thumb.'"> <a href="'.$imported.'">Import</a></p>', ['publish_from' => null]);
    DB::table($draft->getTable())->where('id', $draft->getKey())->update([
        'draft' => json_encode(['blocks' => [['type' => 'button', 'data' => ['url' => $url]]]]),
    ]);
    // Switched off: an active one would be a public use, and the file would stay.
    $redirect = Redirect::query()->create(['tenant_id' => $tenant->getKey(), 'from_path' => '/preisliste', 'to_url' => $url, 'status_code' => 301, 'is_active' => false]);

    // The page (text and stash), the version its creation recorded, the redirect.
    $this->artisan('cms:media:secure', ['--dry-run' => true])
        ->expectsOutputToContain('3 record(s) link a file by its old URL')
        ->assertSuccessful();

    expect($redirect->fresh()->to_url)->toBe($url);

    $this->artisan('cms:media:secure')
        ->expectsOutputToContain('3 record(s) linked a file by its old URL')
        ->assertSuccessful();

    $moved = itemMedia($item);
    $newUrl = $moved->getUrl();
    $stash = json_decode(DB::table($draft->getTable())->where('id', $draft->getKey())->value('draft'), true);
    // A version keeps the columns' raw text: JSON inside JSON.
    $version = json_decode(json_decode(DB::table('versions')->where('versionable_id', $draft->getKey())->value('contents'), true)['blocks'], true);

    expect($newUrl)->not->toBe($url)
        ->and(linkedHtml($draft))->toContain('href="'.$newUrl.'"')
        ->toContain('src="'.$moved->getUrl('thumb').'"')
        ->toContain('href="'.$imported.'"')
        ->and($stash['blocks'][0]['data']['url'])->toBe($newUrl)
        ->and($version[0]['data']['blocks'][0]['data']['content'])->toContain('href="'.$newUrl.'"')
        ->and($redirect->fresh()->to_url)->toBe($newUrl);

    Storage::disk('public')->assertExists($media->getKey().'/01/import.png');

    // A link pasted later from an old page is found on the next run.
    $late = pageLinking($tenant, '<p><a href="'.$url.'">Preisliste</a></p>', ['publish_from' => null]);

    $this->artisan('cms:media:secure')->assertSuccessful();

    expect(linkedHtml($late))->toContain('href="'.$newUrl.'"');
});

it('moves only the media\'s own files out of a legacy directory it shares with an import', function () {
    $tenant = Tenant::factory()->create();
    $item = legacyLibraryImage($tenant, 'fixtures/hidden.png');
    $media = itemMedia($item);
    $imported = $media->getKey().'/01/import.png';
    Storage::disk('public')->put($imported, 'from the old site');

    $this->artisan('cms:media:secure')->assertSuccessful();

    $moved = itemMedia($item);

    expect(PrivateMediaPaths::token($moved))->not->toBeNull();

    Storage::disk('public')->assertExists($moved->getPathRelativeToRoot());
    Storage::disk('public')->assertMissing($media->getKey().'/hidden.png');
    Storage::disk('public')->assertExists($imported);
});

it('rewrites only the links to what moved, not those to an import\'s file beside it', function () {
    $tenant = Tenant::factory()->create();
    $item = legacyLibraryImage($tenant, 'fixtures/hidden.png');
    $media = itemMedia($item);
    $own = $media->getUrl();
    Storage::disk('public')->put($media->getKey().'/fremd.pdf', 'from the old site');
    $foreign = Storage::disk('public')->url($media->getKey().'/fremd.pdf');

    $page = pageLinking($tenant, '<p><a href="'.$foreign.'">PDF</a> <a href="'.$own.'?v=2">Bild</a>, siehe '.$own.'.</p>', ['publish_from' => null]);

    $this->artisan('cms:media:secure')->assertSuccessful();

    $moved = itemMedia($item)->getUrl();

    expect($moved)->not->toBe($own)
        ->and(linkedHtml($page))->toContain('href="'.$foreign.'"')
        ->toContain('href="'.$moved.'?v=2"')
        ->toContain('siehe '.$moved.'.');

    Storage::disk('public')->assertExists($media->getKey().'/fremd.pdf');
});

it('finds a moved file\'s link however it spells the name — escaped, percent-encoded, in a version MySQL hands back', function () {
    $tenant = Tenant::factory()->create();
    $item = legacyLibraryImage($tenant, 'fixtures/preisübersicht.png');
    $media = itemMedia($item);
    $url = $media->getUrl();
    $encoded = Storage::disk('public')->url($media->getKey().'/'.rawurlencode($media->file_name));

    $page = pageLinking($tenant, '<a href="'.$url.'">roh</a> <a href="'.$encoded.'">kodiert</a>', ['publish_from' => null]);
    $redirect = Redirect::query()->create(['tenant_id' => $tenant->getKey(), 'from_path' => '/preise', 'to_url' => $encoded, 'status_code' => 301, 'is_active' => false]);

    // MySQL hands a JSON column back with its slashes unescaped: a version's
    // JSON inside JSON then reads `\\/` where PHP writes `\\\/`.
    $mysql = fn (string $link): string => json_encode(['blocks' => json_encode([['url' => $link]])], JSON_UNESCAPED_SLASHES);
    DB::table('versions')->where('versionable_id', $page->getKey())->update(['contents' => $mysql($url)]);

    $this->artisan('cms:media:secure')->assertSuccessful();

    $moved = itemMedia($item);
    $movedEncoded = Storage::disk('public')->url($moved->getKey().'-'.PrivateMediaPaths::token($moved).'/'.rawurlencode($moved->file_name));

    expect($moved->file_name)->toBe('preisübersicht.png')
        ->and(linkedHtml($page))->toContain('href="'.$moved->getUrl().'"')
        ->toContain('href="'.$movedEncoded.'"')
        ->and($redirect->fresh()->to_url)->toBe($movedEncoded)
        ->and(DB::table('versions')->where('versionable_id', $page->getKey())->value('contents'))->toBe($mysql($moved->getUrl()));
});

it('lists what a dry run would move by its old address, and leaves the files --keep names', function () {
    $tenant = Tenant::factory()->create();
    $moving = itemMedia(legacyLibraryImage($tenant, 'fixtures/preisliste.png'));
    $linkedFromOutside = itemMedia(legacyLibraryImage($tenant, 'fixtures/flyer.png'));

    $this->artisan('cms:media:secure', ['--dry-run' => true])
        ->expectsOutputToContain($moving->getUrl())
        ->expectsOutputToContain($linkedFromOutside->getUrl())
        ->assertSuccessful();

    $this->artisan('cms:media:secure', ['--keep' => (string) $linkedFromOutside->getKey()])->assertSuccessful();

    expect(PrivateMediaPaths::token($moving->fresh()))->not->toBeNull()
        ->and($linkedFromOutside->fresh()->getUrl())->toBe($linkedFromOutside->getUrl());

    Storage::disk('public')->assertExists($linkedFromOutside->getPathRelativeToRoot());
});

it('refuses a --keep that names something other than media ids', function () {
    $this->artisan('cms:media:secure', ['--keep' => 'flyer.png'])
        ->expectsOutputToContain('--keep takes media ids')
        ->assertFailed();
});

it('leaves the file where it is when a copy fails, instead of deleting it', function () {
    $tenant = Tenant::factory()->create();
    $item = legacyLibraryImage($tenant, 'fixtures/hidden.png');
    $media = itemMedia($item);
    Storage::disk('public')->put($media->getKey().'/01/import.png', 'from the old site');

    // A disk that reports a failed copy by returning false, as S3 does by default.
    $disk = Storage::disk('public');
    Storage::set('public', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        public function copy($from, $to)
        {
            return false;
        }
    });

    $this->artisan('cms:media:secure')->assertFailed();

    expect(PrivateMediaPaths::token(itemMedia($item)))->toBeNull();

    Storage::disk('public')->assertExists($media->getKey().'/hidden.png');
});

it('gives a replacement file a directory of its own', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $old = itemMedia($item);

    Storage::disk('public')->put('fixtures/new.png', cmsTestPngBytes());
    $item->driver(app(Cms::mediaDriver()))
        ->addMediaFromDisk('fixtures/new.png', 'public')
        ->toMediaCollection($item->getMediaLibraryCollectionName());

    $new = itemMedia($item);

    expect($new->getKey())->not->toBe($old->getKey())
        ->and(PrivateMediaPaths::token($new))->not->toBeNull()
        ->and(PrivateMediaPaths::token($new))->not->toBe(PrivateMediaPaths::token($old));
});

it('gives a copy its own token, so the original\'s link does not lead to it', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $original = itemMedia($item);

    $copyItem = Cms::mediaItemModel()::query()->create(['tenant_type' => $tenant->getMorphClass(), 'tenant_id' => $tenant->getKey()]);
    $copy = $original->copy($copyItem->driver(app(Cms::mediaDriver())), $item->getMediaLibraryCollectionName());

    expect(PrivateMediaPaths::token($copy))->not->toBeNull()
        ->and(PrivateMediaPaths::token($copy))->not->toBe(PrivateMediaPaths::token($original));
});

it('keeps the plain layout for new uploads when switched off, and still finds private ones', function () {
    $tenant = Tenant::factory()->create();
    $private = makeLibraryImage($tenant, 'fixtures/a.png');

    config(['cms.media.private' => false]);

    $plain = makeLibraryImage($tenant, 'fixtures/b.png');

    expect(itemMedia($plain)->getPathRelativeToRoot())->toBe(itemMedia($plain)->getKey().'/b.png');
    Storage::disk('public')->assertExists(itemMedia($private)->getPathRelativeToRoot());
});

it('stays out of the way of an app with a path generator of its own', function () {
    forgetCustomPathGenerators();
    config(['media-library.path_generator' => PrivateMediaPathGenerator::class]);

    PrivateMediaPaths::registerPathGenerator();

    expect(PrivateMediaPaths::generatorRegistered())->toBeFalse()
        ->and(PrivateMediaPaths::enabled())->toBeFalse();

    config(['media-library.path_generator' => DefaultPathGenerator::class]);
    PrivateMediaPaths::registerPathGenerator();

    expect(PrivateMediaPaths::enabled())->toBeTrue();
});

it('refuses to move files the private generator does not lay out, instead of deleting them', function () {
    $item = legacyLibraryImage(Tenant::factory()->create(), 'fixtures/a.png');
    $path = itemMedia($item)->getPathRelativeToRoot();

    // An app registers a generator of its own for the library after the CMS did.
    PathGeneratorFactory::setCustomPathGenerators(MediaLibrary::ITEM_MORPH_ALIAS, DefaultPathGenerator::class);
    PathGeneratorFactory::setCustomPathGenerators(MediaLibraryItem::class, DefaultPathGenerator::class);

    try {
        expect(fn () => PrivateMediaPaths::moveToPrivateDirectory(itemMedia($item)))->toThrow(LogicException::class);

        Storage::disk('public')->assertExists($path);
        expect(PrivateMediaPaths::token(itemMedia($item)))->toBeNull();
    } finally {
        forgetCustomPathGenerators();
        PrivateMediaPaths::registerPathGenerator();
    }
});

it('stays off when the app configured a generator for its own item model', function () {
    $appModel = (new class extends MediaLibraryItem {})::class;

    forgetCustomPathGenerators();
    config(['media-library.custom_path_generators' => [$appModel => DefaultPathGenerator::class]]);

    try {
        PrivateMediaPaths::registerPathGenerator();

        expect(PrivateMediaPaths::enabled())->toBeFalse();
    } finally {
        config(['media-library.custom_path_generators' => []]);
        PrivateMediaPaths::registerPathGenerator();
    }
});

it('stays off while the app switched the media library off', function () {
    Cms::disableMediaLibrary();

    expect(PrivateMediaPaths::enabled())->toBeFalse();
});

it('does not point a row at a new directory when there is nothing to move, nor move a file twice', function () {
    $tenant = Tenant::factory()->create();
    $legacy = legacyLibraryImage($tenant);
    $media = itemMedia($legacy);

    Storage::disk('public')->deleteDirectory(PrivateMediaPaths::baseDirectory($media));

    expect(PrivateMediaPaths::moveToPrivateDirectory($media))->toBeFalse()
        ->and(PrivateMediaPaths::token(itemMedia($legacy)))->toBeNull();

    $private = itemMedia(makeLibraryImage($tenant, 'fixtures/b.png'));
    $path = $private->getPathRelativeToRoot();

    expect(PrivateMediaPaths::moveToPrivateDirectory($private))->toBeFalse();
    Storage::disk('public')->assertExists($path);
});

it('lets one run at a time move files', function () {
    Cache::lock('cms:media:secure', 60)->get();

    $this->artisan('cms:media:secure')
        ->expectsOutputToContain('Another cms:media:secure run is moving files')
        ->assertFailed();

    // A dry run moves nothing and need not wait.
    $this->artisan('cms:media:secure', ['--dry-run' => true])->assertSuccessful();
});

it('lets cms:media:prune remove a directory a move left behind, never the current one', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/a.png');
    $media = itemMedia($item);

    $stale = $media->getKey().'-'.str_repeat('x', 24).'/a.png';
    Storage::disk('public')->put($stale, 'old copy');

    $this->artisan('cms:media:prune', ['--force' => true, '--ignore-queue' => true])->assertSuccessful();

    Storage::disk('public')->assertMissing($stale);
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
});

it('lets cms:media:prune report a folder that only looks like a private directory, never delete it', function () {
    $item = makeLibraryImage(Tenant::factory()->create(), 'fixtures/a.png');
    $known = itemMedia($item)->getKey();

    // Folders of the app's on the same disk: one with an id nobody has, one
    // whose number happens to be a media row's.
    Storage::disk('public')->put('2024-Betriebsanleitungen/handbuch.pdf', 'pdf');
    Storage::disk('public')->put("{$known}-Jahresabschlussbericht/bilanz.pdf", 'pdf');

    $this->artisan('cms:media:prune', ['--force' => true, '--ignore-queue' => true])->assertSuccessful();

    Storage::disk('public')->assertExists('2024-Betriebsanleitungen/handbuch.pdf');
    Storage::disk('public')->assertExists("{$known}-Jahresabschlussbericht/bilanz.pdf");
});

it('lets cms:media:prune keep the directory of a move cut short, where the only copy may be', function () {
    $tenant = Tenant::factory()->create();
    // Enough rows that one broken row stays below the drift guard.
    $items = collect(range(1, 11))->map(fn (int $i) => makeLibraryImage($tenant, "fixtures/bild-{$i}.png"));
    $media = itemMedia($items->first());
    $disk = Storage::disk('public');
    $cutShort = $media->getKey().'-'.str_repeat('y', 24);

    // The directory moved; the run died before the row learned the new token.
    rename($disk->path(PrivateMediaPaths::baseDirectory($media)), $disk->path($cutShort));

    $this->artisan('cms:media:prune', ['--force' => true, '--ignore-queue' => true])
        ->expectsOutputToContain('Private directories kept');

    $disk->assertExists("{$cutShort}/{$media->file_name}");
});

it('lets cms:media:prune remove what a move left in the old directory, never an import\'s files there', function () {
    $item = legacyLibraryImage(Tenant::factory()->create(), 'fixtures/a.png');
    $old = PrivateMediaPaths::baseDirectory(itemMedia($item));

    $this->artisan('cms:media:secure')->assertSuccessful();

    // The old copy could not be removed; an import's file sits next to it.
    Storage::disk('public')->put("{$old}/a.png", 'old copy');
    Storage::disk('public')->put("{$old}/conversions/a-thumb.webp", 'old thumb');
    Storage::disk('public')->put("{$old}/import.pdf", 'from the old site');

    $this->artisan('cms:media:prune', ['--force' => true, '--ignore-queue' => true])->assertSuccessful();

    Storage::disk('public')->assertMissing("{$old}/a.png");
    Storage::disk('public')->assertMissing("{$old}/conversions/a-thumb.webp");
    Storage::disk('public')->assertExists("{$old}/import.pdf");
    Storage::disk('public')->assertExists(itemMedia($item)->getPathRelativeToRoot());
});
