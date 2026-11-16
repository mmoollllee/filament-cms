<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\MediaReferenceSource;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Enums\TenantVisibility;
use Mmoollllee\Cms\Filament\Support\MediaUsageList;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Media\MediaReferences;
use Mmoollllee\Cms\Support\Media\References\MediaReferenceScanner;
use Mmoollllee\Cms\Support\Media\References\MediaUsage;
use Mmoollllee\Cms\Support\Preview\PreviewMode;
use Workbench\App\Models\Content;
use Workbench\App\Models\Fragment;
use Workbench\App\Models\Tenant;

/*
 * Where is a library item used? The answer drives the usage list in the
 * Mediathek, the delete protection and the private media paths, so every
 * place a media field writes to has to be found — and nothing else.
 */

beforeEach(function () {
    Storage::fake('public');
});

it('finds media fields in blocks, page header and SEO, and nothing else', function () {
    $scanner = MediaReferenceScanner::make();

    $ids = $scanner->scan([
        'blocks' => sectionWith([mediaBlock(11), ['type' => 'media', 'data' => ['media_path' => '12', 'poster_path' => 13]]]),
        'payload' => ['hero' => ['image' => 14, 'thumbnail' => 'tenants/demo/legacy.jpg', 'float_image' => null, 'size' => 'gross']],
        'meta' => ['og_image' => 15, 'og_image_url' => 'https://example.com/x.jpg'],
        // Integers under keys no media field uses are not references.
        'sort' => 16,
        'layout' => ['columns' => 17, 'gap' => '18'],
    ]);

    expect($ids)->toEqualCanonicalizing([11, 12, 13, 14, 15]);
});

it('reads rich-text images in stored HTML and TipTap JSON, in every id shape the editor writes', function () {
    $html = '<p>Text</p><img src="/x.jpg" data-id="21"><p><img alt="b" data-id="'.pickerKey(22, 'thumb').'" src="y"></p>'
        // Other nodes carry data-id too: custom blocks, merge tags, mentions.
        .'<div data-type="customBlock" data-id="buttonGroup"></div><span data-type="mention" data-id="99"></span>';

    $json = ['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['id' => pickerKey(23), 'src' => 'z']],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]],
    ]];

    expect(MediaReferenceScanner::make()->scan(['content' => $html, 'json' => $json]))
        ->toEqualCanonicalizing([21, 22, 23]);
});

it('recognizes the media fields of app blocks by their key, without mistaking sizes for ids', function () {
    $ids = MediaReferenceScanner::make()->scan(['data' => [
        'image_path' => 41,
        'team_photo' => '42',
        'gallery' => [43, 44],
        'download_file' => 45,
        'product_datasheet' => 47,
        'image_width' => 800,
        'file_size' => 12345,
        'logo_height' => 60,
        'profile' => 46,
        'has_brochure' => '1',
        // An id field, and keys written in camelCase.
        'image_id' => 48,
        'heroImage' => 49,
        // Counts are not ids, whatever they count.
        'max_files' => 3,
        'num_images' => 4,
    ]]);

    expect($ids)->toEqualCanonicalizing([41, 42, 43, 44, 45, 47, 48, 49]);
});

it('recognizes German media keys, compounds included, but not flags', function () {
    $ids = MediaReferenceScanner::make()->scan(['payload' => [
        // The keys one consumer app kept from its WordPress days.
        'galerie' => [51, '52'],
        'masszeichnung' => 53,
        'datenblatt' => 57,
        'titelbild' => 54,
        'produkt_foto' => 55,
        'pdf_datei' => 56,
        'firmenlogo' => 58,
        'is_video' => '1',
        'show_image' => '1',
        'zeige_bild' => '1',
        'anzahl_bilder' => 5,
        'bildbreite' => 800,
        'titelBild' => 59,
    ]]);

    expect($ids)->toEqualCanonicalizing([51, 52, 53, 54, 55, 56, 57, 58, 59]);
});

it('finds a library file referenced by its URL, relative or absolute, under any key', function () {
    $tenant = Tenant::factory()->create();
    $linked = makeLibraryImage($tenant, 'fixtures/preisliste.png');
    $pasted = makeLibraryImage($tenant, 'fixtures/flyer.png');
    $urlOf = fn ($item): string => $item->refresh()->getFirstMedia($item->getMediaLibraryCollectionName())->getUrl();

    $ids = MediaReferenceScanner::make()->scan([
        'content' => '<p><a href="'.parse_url($urlOf($linked), PHP_URL_PATH).'">Preisliste</a></p>',
        'cta_url' => $urlOf($pasted),
        // Not a library directory, and not a library file.
        'legacy' => '/storage/tenants/demo/logo.png',
    ]);

    expect($ids)->toEqualCanonicalizing([$linked->getKey(), $pasted->getKey()]);
});

it('takes every element of a multiple picker under a registered key', function () {
    Cms::registerMediaReferenceKeys(['portraits']);

    expect(MediaReferenceScanner::make()->scan(['data' => ['portraits' => [31, '32', pickerKey(33)]]]))
        ->toEqualCanonicalizing([31, 32, 33]);
});

it('reports a published page as a public use', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $content = Content::factory()->for($tenant)->create(['title' => 'Über uns', 'blocks' => sectionWith([mediaBlock($item->getKey())])]);

    $usage = MediaReferences::of($item)->sole();

    expect($usage)->toBeInstanceOf(MediaUsage::class)
        ->and($usage->owner->getKey())->toBe($content->getKey())
        ->and($usage->label)->toBe('Über uns')
        ->and($usage->isPublic())->toBeTrue()
        ->and($usage->statusLabel())->toBe('veröffentlicht');
});

it('tells unpublished, scheduled and legacy members-only pages apart from public ones', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $blocks = sectionWith([mediaBlock($item->getKey())]);

    Content::factory()->for($tenant)->create(['title' => 'offline', 'blocks' => $blocks, 'publish_from' => null]);
    Content::factory()->for($tenant)->create(['title' => 'geplant', 'blocks' => $blocks, 'publish_from' => now()->addWeek()]);
    Content::factory()->for($tenant)->create(['title' => 'members', 'blocks' => $blocks, 'visibility' => ContentVisibility::Members]);
    Content::factory()->for($tenant)->create(['title' => 'abgelaufen', 'blocks' => $blocks, 'publish_from' => now()->subWeek(), 'publish_until' => now()->subDay()]);

    $byLabel = MediaReferences::of($item)->keyBy('label');

    expect($byLabel)->toHaveCount(4)
        ->and($byLabel->filter(fn (MediaUsage $usage) => $usage->isPublic()))->toBeEmpty()
        ->and($byLabel['geplant']->isUpcoming())->toBeTrue()
        ->and($byLabel['geplant']->statusLabel())->toBe('geplant')
        ->and($byLabel['offline']->statusLabel())->toBe('nicht veröffentlicht');
});

it('counts a reference that only exists in the unapplied draft, without calling it public', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $content = Content::factory()->for($tenant)->create();

    $content->stashDraft(['blocks' => sectionWith([mediaBlock($item->getKey())])]);

    $usage = MediaReferences::of($item)->sole();

    expect($usage->drafted)->toBeTrue()
        ->and($usage->applied)->toBeFalse()
        ->and($usage->isPublic())->toBeFalse()
        ->and($usage->statusLabel())->toBe('nur im Entwurf');
});

it('does not mistake the draft for the applied version while the preview is active', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $content = Content::factory()->for($tenant)->create();
    $content->stashDraft(['blocks' => sectionWith([mediaBlock($item->getKey())])]);

    app(PreviewMode::class)->activate();

    expect(MediaReferences::of($item)->sole()->applied)->toBeFalse();
});

it('finds references in fragments and in the tenant\'s branding', function () {
    $tenant = Tenant::factory()->create();
    $logo = makeLibraryImage($tenant, 'fixtures/logo.png');
    $teaser = makeLibraryImage($tenant, 'fixtures/teaser.png');

    $tenant->update(['logo_path' => (string) $logo->getKey()]);
    Fragment::query()->create(['tenant_id' => $tenant->getKey(), 'title' => 'Footer', 'slug' => 'footer', 'blocks' => sectionWith([mediaBlock($teaser->getKey())])]);

    $logoUsage = MediaReferences::of($logo)->sole();
    $teaserUsage = MediaReferences::of($teaser)->sole();

    expect($logoUsage->owner)->toBeInstanceOf(Tenant::class)
        ->and($logoUsage->detail)->toBe('Logo')
        ->and($logoUsage->isPublic())->toBeTrue()
        ->and($teaserUsage->owner)->toBeInstanceOf(Fragment::class)
        ->and($teaserUsage->label)->toBe('Footer')
        ->and($teaserUsage->isPublic())->toBeTrue();
});

it('treats nothing on an archived tenant as public', function () {
    $tenant = Tenant::factory()->create(['visibility' => TenantVisibility::Archived]);
    $item = makeLibraryImage($tenant);
    $tenant->update(['logo_path' => (string) $item->getKey()]);
    Content::factory()->for($tenant)->create(['blocks' => sectionWith([mediaBlock($item->getKey())])]);

    expect(MediaReferences::of($item))->toHaveCount(2)
        ->and(MediaReferences::of($item)->filter->isPublic())->toBeEmpty();
});

it('treats nothing on a members-only tenant as public', function () {
    // Guests get a 403 there: the site is still being finished.
    $tenant = Tenant::factory()->create(['visibility' => TenantVisibility::Members]);
    $item = makeLibraryImage($tenant);
    Content::factory()->for($tenant)->create(['blocks' => sectionWith([mediaBlock($item->getKey())])]);

    $usage = MediaReferences::of($item)->sole();

    expect($usage->isPublic())->toBeFalse()
        ->and($usage->statusLabel())->toBe('nicht veröffentlicht');
});

it('only looks in the item\'s own tenant', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);

    Content::factory()->for($other)->create(['blocks' => sectionWith([mediaBlock($item->getKey())])]);

    expect(MediaReferences::of($item))->toBeEmpty();
});

it('asks registered sources of the app as well', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);

    $source = new class implements MediaReferenceSource
    {
        public static int $itemId = 0;

        public static function mediaUsagesForTenants(Collection $tenants): iterable
        {
            yield new MediaUsage(static::$itemId, $tenants->first(), 'Team', applied: true, drafted: false, live: true);
        }
    };
    $source::$itemId = (int) $item->getKey();

    Cms::registerMediaReferenceSources([$source::class]);

    expect(MediaReferences::of($item)->pluck('label')->all())->toBe(['Team']);
});

it('recognizes the key of every media field the package defines', function () {
    // A media field whose key the scanner does not take for one would be
    // invisible to the usage list and the delete protection.
    $keys = collect(\Illuminate\Support\Facades\File::allFiles(__DIR__.'/../../src'))
        ->flatMap(function (\SplFileInfo $file): array {
            $source = (string) file_get_contents($file->getPathname());

            preg_match_all("/MediaField::(?:image|media|document)\\(\\s*'([^']+)'/", $source, $direct);
            preg_match_all("/->upload\\(\\s*'([^']+)'/", $source, $viaHelper);

            return [...$direct[1], ...$viaHelper[1]];
        })
        ->map(fn (string $key): string => \Illuminate\Support\Str::afterLast($key, '.'))
        ->unique()
        ->values();

    $scanner = MediaReferenceScanner::make();

    expect($keys)->toContain('media_path', 'og_image', 'logo_path', 'float_image')
        ->and($keys->reject(fn (string $key): bool => $scanner->scan([$key => 7]) === [7])->values()->all())->toBe([]);
});

it('counts a file in a switched-off block as used, but not as shown', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $off = ['type' => 'media', 'data' => ['media_path' => $item->getKey(), 'active' => false]];

    Content::factory()->for($tenant)->create(['title' => 'Über uns', 'blocks' => sectionWith([$off])]);
    // A section switched off hides what is inside it, too.
    Content::factory()->for($tenant)->create(['title' => 'Team', 'blocks' => [
        ['type' => 'section', 'data' => ['active' => false, 'blocks' => [mediaBlock($item->getKey())]]],
    ]]);

    expect(MediaReferences::of($item)->map(fn (MediaUsage $usage): array => [$usage->label, $usage->isPublic(), $usage->statusLabel()])->sortBy(0)->values()->all())
        ->toBe([['Team', false, 'ausgeblendet'], ['Über uns', false, 'ausgeblendet']]);

    // Shown in another block of the same page: that is what counts.
    Content::factory()->for($tenant)->create(['title' => 'Kontakt', 'blocks' => sectionWith([$off, mediaBlock($item->getKey())])]);

    expect(MediaReferences::of($item)->firstWhere('label', 'Kontakt')->isPublic())->toBeTrue();
});

it('finds the files a menu or a redirect links by their URL', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant, 'fixtures/preisliste.png');
    $url = $item->getFirstMedia($item->getMediaLibraryCollectionName())->getUrl();

    $menuId = DB::table((new Menu)->getTable())->insertGetId(['name' => 'Footer', 'tenant_id' => $tenant->getKey(), 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('menu_items')->insert(['menu_id' => $menuId, 'title' => 'Preisliste', 'url' => $url, 'order' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Redirect::query()->create(['tenant_id' => $tenant->getKey(), 'from_path' => '/preisliste', 'to_url' => $url, 'status_code' => 301, 'is_active' => true]);

    $usages = MediaReferences::of($item)->keyBy(fn (MediaUsage $usage): string => class_basename($usage->owner));

    expect($usages->keys()->sort()->values()->all())->toBe(['Menu', 'Redirect'])
        ->and($usages['Menu']->label)->toBe('Footer')
        ->and($usages['Menu']->detail)->toBe('Preisliste')
        // In no location yet: no page renders it.
        ->and($usages['Menu']->isPublic())->toBeFalse()
        ->and($usages['Redirect']->label)->toBe('/preisliste')
        ->and($usages['Redirect']->isPublic())->toBeTrue();

    DB::table('menu_locations')->insert(['menu_id' => $menuId, 'tenant_id' => $tenant->getKey(), 'location' => 'footer', 'created_at' => now(), 'updated_at' => now()]);
    MediaReferences::flush();

    expect(MediaReferences::of($item)->first(fn (MediaUsage $usage): bool => $usage->owner instanceof Menu)->isPublic())->toBeTrue()
        ->and((string) MediaUsageList::html(MediaReferences::of($item)))->toContain('(Menü, veröffentlicht)', '(Weiterleitung, veröffentlicht)');
});

it('names the records of an app\'s own source in the usage list', function () {
    $usage = new MediaUsage(1, new class extends \Illuminate\Database\Eloquent\Model {}, 'Max Mustermann', applied: true, drafted: false, live: true);

    expect((string) MediaUsageList::html(collect([$usage])))->toContain('Max Mustermann', '(Eintrag, veröffentlicht)');
});

it('answers the file info from a cache that every write through the CMS renews', function () {
    $tenant = Tenant::factory()->create();
    $item = makeLibraryImage($tenant);
    $page = Content::factory()->for($tenant)->create(['title' => 'Über uns', 'blocks' => []]);

    expect(MediaReferences::shown($item))->toBeEmpty();

    $page->update(['blocks' => sectionWith([mediaBlock($item->getKey())])]);

    expect(MediaReferences::shown($item)->map(fn (MediaUsage $usage): string => $usage->label)->all())->toBe(['Über uns']);

    // Written past the CMS, as an app's own table would be: the file info
    // may lag behind for a while, the delete guard never does.
    DB::table($page->getTable())->where('id', $page->getKey())->update(['blocks' => '[]']);
    MediaReferences::flush();

    expect(MediaReferences::shown($item))->toHaveCount(1)
        ->and(MediaReferences::of($item))->toBeEmpty();

    // The draft stash writes past the model events, and renews it itself.
    $page->stashDraft(['blocks' => sectionWith([mediaBlock($item->getKey())])]);

    expect(MediaReferences::shown($item)->sole()->statusLabel())->toBe('nur im Entwurf');
});
