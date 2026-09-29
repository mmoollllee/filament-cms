<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Support\Media\BlurredPlaceholderGenerator;
use Mmoollllee\Cms\Support\Media\ImageMetadata;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\TinyPlaceholderGenerator;
use Workbench\App\Models\Tenant;

/*
 * The blurred placeholder travels inline in every page, so it must stay a ~1 KB thumbnail:
 * a phone photo's metadata (EXIF with its embedded preview, ICC, XMP) made it 40+ KB. And it
 * has to be rendered where the browser shows it — as a srcset candidate it never was.
 */

beforeEach(function () {
    Storage::fake('public');
    config(['media-library.queue_conversions_by_default' => false]);
});

/**
 * A real JPEG, optionally carrying an EXIF-sized APP1 segment and a comment right after SOI.
 */
function placeholderTestJpeg(int $width = 400, int $height = 300, bool $withMetadata = false): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x++) {
        imageline($image, $x, 0, $x, $height, imagecolorallocate($image, (int) ($x / $width * 255), 90, 160));
    }

    ob_start();
    imagejpeg($image, null, 85);
    $jpeg = (string) ob_get_clean();

    if (! $withMetadata) {
        return $jpeg;
    }

    $exif = "Exif\0\0".str_repeat('x', 30000);
    $comment = 'shot on a phone';

    return "\xFF\xD8"
        ."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
        ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment
        .substr($jpeg, 2);
}

/**
 * A library image from real JPEG bytes (the shared helper writes PNG bytes).
 */
function makeLibraryJpeg(Tenant $tenant, bool $withMetadata = false): Media
{
    $item = makeLibraryImage($tenant, 'fixtures/photo.jpg');
    $media = $item->getItem();

    // Swap in JPEG bytes and let the library derive everything from them.
    Storage::disk('public')->put($media->getPathRelativeToRoot(), placeholderTestJpeg(withMetadata: $withMetadata));
    $media->mime_type = 'image/jpeg';
    $media->save();

    Artisan::call('media-library:regenerate', ['--ids' => [$media->getKey()], '--with-responsive-images' => true, '--force' => true]);

    MediaUrlResolver::flush();

    return $media->refresh();
}

/**
 * The JPEG inside a stored placeholder (a base64 SVG wrapping a base64 JPEG).
 */
function placeholderJpeg(string $placeholder): string
{
    $svg = base64_decode(substr($placeholder, strpos($placeholder, ',') + 1));
    preg_match('#data:image/jpeg;base64,([A-Za-z0-9+/=]+)#', $svg, $match);

    return base64_decode($match[1]);
}

it('strips application segments and comments but keeps the image', function () {
    $jpeg = placeholderTestJpeg(withMetadata: true);
    $stripped = ImageMetadata::strip($jpeg, keepColorProfile: false);

    expect(ImageMetadata::has($jpeg))->toBeTrue()
        ->and(ImageMetadata::has($stripped))->toBeFalse()
        ->and(strlen($stripped))->toBeLessThan(strlen($jpeg) - 30000)
        ->and(getimagesizefromstring($stripped))->toMatchArray([0 => 400, 1 => 300]);
});

it('leaves a clean JPEG and anything that is no JPEG untouched', function () {
    // GD itself writes a "CREATOR: gd-jpeg" comment, so a stripped JPEG is the clean one.
    $clean = ImageMetadata::strip(placeholderTestJpeg(), keepColorProfile: false);

    expect(ImageMetadata::strip($clean, keepColorProfile: false))->toBe($clean)
        ->and(ImageMetadata::strip('not an image'))->toBe('not an image');
});

it('generates a 32px placeholder without the source metadata', function (string $driver) {
    if (! extension_loaded($driver)) {
        $this->markTestSkipped("ext-{$driver} is not installed");
    }

    config(['media-library.image_driver' => $driver]);

    $directory = sys_get_temp_dir().'/cms-placeholder-'.uniqid();
    mkdir($directory);
    file_put_contents("{$directory}/source.jpg", placeholderTestJpeg(withMetadata: true));

    (new BlurredPlaceholderGenerator)->generateTinyPlaceholder("{$directory}/source.jpg", "{$directory}/tiny.jpg");
    $tiny = (string) file_get_contents("{$directory}/tiny.jpg");

    expect(getimagesizefromstring($tiny)[0])->toBe(32)
        ->and(ImageMetadata::has($tiny, keepColorProfile: false))->toBeFalse();

    array_map('unlink', glob("{$directory}/*"));
    rmdir($directory);
})->with(['gd', 'imagick']);

it('replaces the stock placeholder generator', function () {
    expect(app(TinyPlaceholderGenerator::class))->toBeInstanceOf(BlurredPlaceholderGenerator::class);
});

it('keeps the placeholder out of the srcset and hands it out on its own', function () {
    $media = makeLibraryJpeg(Tenant::factory()->create(), withMetadata: true);

    $srcset = MediaUrlResolver::srcset($media->model_id);
    $placeholder = MediaUrlResolver::placeholder($media->model_id);

    expect($srcset)->toContain('w')->not->toContain('data:')
        ->and($placeholder)->toStartWith('data:image/svg+xml;base64,')
        ->and(ImageMetadata::has(placeholderJpeg($placeholder), keepColorProfile: false))->toBeFalse();
});

it('gives a transparent-capable PNG no placeholder', function () {
    $item = makeLibraryImage(Tenant::factory()->create(), 'fixtures/logo.png');

    expect(MediaUrlResolver::placeholder($item->getKey()))->toBeNull();
});

it('shows the placeholder behind the image of both image components', function () {
    $media = makeLibraryJpeg(Tenant::factory()->create());
    $placeholder = MediaUrlResolver::placeholder($media->model_id);

    $image = Blade::render('<x-site.image :media="$ref" />', ['ref' => $media->model_id]);
    $block = Blade::render('<x-block::media :data="$data" />', [
        'data' => ['media_path' => $media->model_id, 'active' => true],
    ]);

    foreach ([$image, $block] as $html) {
        expect($html)->toContain('style="background-image:url('.$placeholder.');background-size:cover');
    }
});

it('rebuilds only placeholders that carry metadata or are missing', function () {
    $tenant = Tenant::factory()->create();
    $laden = makeLibraryJpeg($tenant);
    $clean = makeLibraryJpeg($tenant);
    $missing = makeLibraryJpeg($tenant);

    // Plant what the stock generator used to leave behind, and a gap.
    $responsive = $laden->responsive_images;
    $fatJpeg = placeholderTestJpeg(32, 24, withMetadata: true);
    $responsive['responsive']['base64svg'] = 'data:image/svg+xml;base64,'.base64_encode(
        '<svg><image xlink:href="data:image/jpeg;base64,'.base64_encode($fatJpeg).'"></image></svg>'
    );
    $laden->responsive_images = $responsive;
    $laden->save();

    $responsive = $missing->responsive_images;
    unset($responsive['responsive']['base64svg']);
    $missing->responsive_images = $responsive;
    $missing->save();

    // Each image carries two placeholders here: the `responsive` conversion's and the
    // original-level one, which the regenerate in makeLibraryJpeg() adds.
    $untouched = $clean->responsive_images['responsive']['base64svg'];
    $planted = $laden->responsive_images['responsive']['base64svg'];

    $this->artisan('cms:media:placeholders', ['--dry-run' => true])
        ->expectsOutputToContain('Would rebuild 2 of 6 placeholder(s).')
        ->assertSuccessful();

    expect($laden->refresh()->responsive_images['responsive']['base64svg'])->toBe($planted);

    $this->artisan('cms:media:placeholders')
        ->expectsOutputToContain('Rebuilt 2 of 6 placeholder(s).')
        ->assertSuccessful();

    expect(ImageMetadata::has(placeholderJpeg($laden->refresh()->responsive_images['responsive']['base64svg']), keepColorProfile: false))->toBeFalse()
        ->and($missing->refresh()->responsive_images['responsive']['base64svg'] ?? null)->toStartWith('data:image/svg+xml;base64,')
        ->and($clean->refresh()->responsive_images['responsive']['base64svg'])->toBe($untouched);
});

it('gives the image its intrinsic size so the box is reserved before the file arrives', function () {
    $media = makeLibraryJpeg(Tenant::factory()->create());

    expect(MediaUrlResolver::dimensions($media->model_id))->toBe(['width' => 400, 'height' => 300])
        ->and(Blade::render('<x-site.image :media="$ref" />', ['ref' => $media->model_id]))
        ->toContain('width="400"')->toContain('height="300"')
        ->and(Blade::render('<x-site.image :media="$ref" width="80" />', ['ref' => $media->model_id]))
        ->toContain('width="80"')->not->toContain('width="400"')
        ->and(Blade::render('<x-block::media :data="$data" />', ['data' => ['media_path' => $media->model_id, 'active' => true]]))
        ->toContain('width="400"')->toContain('height="300"');
});

it('has no size and no placeholder for a legacy path', function () {
    expect(MediaUrlResolver::dimensions('content-blocks/legacy.jpg'))->toBeNull()
        ->and(MediaUrlResolver::placeholder('content-blocks/legacy.jpg'))->toBeNull();
});
