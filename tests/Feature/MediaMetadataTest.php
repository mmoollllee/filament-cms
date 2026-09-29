<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Support\Media\ImageMetadata;
use Mmoollllee\Cms\Support\Media\MetadataFreeFilesystem;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Workbench\App\Models\Tenant;

/*
 * Camera metadata stays in the uploaded original and nowhere else. Phone photos carry GPS
 * coordinates, device and capture time; conversions and srcset candidates are public files,
 * so they must not. Removal works on segments and chunks — no re-encode, pixels untouched —
 * and keeps the colour profile, without which a Display P3 photo renders dull.
 */

beforeEach(function () {
    Storage::fake('public');
});

/**
 * A minimal but valid EXIF block (big-endian TIFF, one IFD entry: Make) that image libraries
 * actually parse and carry over — unlike arbitrary APP1 bytes.
 */
function metadataTestExif(string $make = 'TestCam'): string
{
    $value = $make."\0";

    return "Exif\0\0"
        ."MM\0*".pack('N', 8)
        .pack('n', 1)
        .pack('nnNN', 0x010F, 2, strlen($value), 26)
        .pack('N', 0)
        .$value;
}

function metadataTestJpeg(): string
{
    $image = imagecreatetruecolor(64, 48);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
    ob_start();
    imagejpeg($image, null, 90);

    return ImageMetadata::strip((string) ob_get_clean(), keepColorProfile: false);
}

/**
 * A JPEG with EXIF, an ICC profile, an MPF segment (a phone's gain map index) and a comment.
 */
function metadataTestJpegWithEverything(): string
{
    $segment = fn (int $marker, string $payload): string => "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    $exif = metadataTestExif();

    return "\xFF\xD8"
        .$segment(0xE1, $exif)
        .$segment(0xE2, "ICC_PROFILE\0\x01\x01".str_repeat('p', 64))
        .$segment(0xE2, "MPF\0".str_repeat('m', 32))
        .$segment(0xFE, 'shot on a phone')
        .substr(metadataTestJpeg(), 2);
}

/**
 * An extended WebP: VP8X announcing ICC, EXIF and XMP, and the three chunks around the image.
 */
function metadataTestWebp(): string
{
    $image = imagecreatetruecolor(64, 48);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
    ob_start();
    imagewebp($image, null, 80);
    $simple = (string) ob_get_clean();

    $chunk = fn (string $fourCc, string $payload): string => $fourCc.pack('V', strlen($payload)).$payload.(strlen($payload) % 2 ? "\0" : '');
    $vp8x = chr(0x20 | 0x08 | 0x04)."\0\0\0".substr(pack('V', 63), 0, 3).substr(pack('V', 47), 0, 3);

    $chunks = $chunk('VP8X', $vp8x)
        .$chunk('ICCP', str_repeat('p', 64))
        .substr($simple, 12)
        .$chunk('EXIF', substr(metadataTestExif(), 6))
        .$chunk('XMP ', '<x:xmpmeta>GPS</x:xmpmeta>');

    return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
}

/**
 * @return list<string> the chunk FourCCs of a WebP
 */
function webpChunks(string $webp): array
{
    $chunks = [];

    for ($offset = 12; $offset + 8 <= strlen($webp);) {
        $size = unpack('V', substr($webp, $offset + 4, 4))[1];
        $chunks[] = substr($webp, $offset, 4);
        $offset += 8 + $size + ($size % 2);
    }

    return $chunks;
}

function metadataTestPng(): string
{
    $image = imagecreatetruecolor(16, 16);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $afterHeader = 8 + 25;

    return substr($png, 0, $afterHeader)
        .$chunk('iCCP', "icc\0\0".gzcompress(str_repeat('p', 64)))
        .$chunk('tEXt', "Author\0someone")
        .$chunk('eXIf', substr(metadataTestExif(), 6))
        .substr($png, $afterHeader);
}

it('drops EXIF, MPF and comments from a JPEG but keeps its colour profile and pixels', function () {
    $jpeg = metadataTestJpegWithEverything();
    $stripped = ImageMetadata::strip($jpeg);

    expect($stripped)->not->toContain('TestCam')
        ->not->toContain("MPF\0")
        ->not->toContain('shot on a phone')
        ->toContain("ICC_PROFILE\0")
        ->and(ImageMetadata::strip($jpeg, keepColorProfile: false))->not->toContain('ICC_PROFILE')
        ->and(getimagesizefromstring($stripped))->toMatchArray([0 => 64, 1 => 48])
        ->and(ImageMetadata::has($stripped))->toBeFalse();
});

it('reads past fill bytes in front of a marker', function () {
    $jpeg = metadataTestJpegWithEverything();
    $padded = "\xFF\xD8\xFF\xFF\xFF".substr($jpeg, 2);
    $stripped = ImageMetadata::strip($padded);

    expect($stripped)->not->toContain('TestCam')->toContain("ICC_PROFILE\0")
        ->and(getimagesizefromstring($stripped))->toMatchArray([0 => 64, 1 => 48]);
});

it('drops the EXIF and XMP chunks of a WebP and clears their flags', function () {
    $stripped = ImageMetadata::strip(metadataTestWebp());

    expect(webpChunks($stripped))->toBe(['VP8X', 'ICCP', 'VP8 '])
        ->and(ord($stripped[20]) & 0x0C)->toBe(0)
        ->and(ord($stripped[20]) & 0x20)->toBe(0x20)
        ->and(unpack('V', substr($stripped, 4, 4))[1])->toBe(strlen($stripped) - 8)
        ->and(webpChunks(ImageMetadata::strip(metadataTestWebp(), keepColorProfile: false)))->toBe(['VP8X', 'VP8 '])
        ->and(imagecreatefromstring(ImageMetadata::strip(metadataTestWebp(), keepColorProfile: false)))->not->toBeFalse();
});

it('drops the text and EXIF chunks of a PNG', function () {
    $stripped = ImageMetadata::strip(metadataTestPng());

    expect($stripped)->not->toContain('tEXt')->not->toContain('eXIf')->toContain('iCCP')
        ->and(imagecreatefromstring(ImageMetadata::strip(metadataTestPng(), keepColorProfile: false)))->not->toBeFalse();
});

it('leaves formats it does not handle untouched', function () {
    expect(ImageMetadata::strip('GIF89a...'))->toBe('GIF89a...');
});

it('routes every derivative through the metadata-free filesystem', function () {
    expect(app(Filesystem::class))->toBeInstanceOf(MetadataFreeFilesystem::class);
});

it('keeps the metadata in the original only', function () {
    config([
        'media-library.image_driver' => 'imagick',
        'media-library.queue_conversions_by_default' => false,
    ]);

    $item = makeLibraryImage(Tenant::factory()->create(), 'fixtures/phone.jpg');
    $media = $item->getItem();
    Storage::disk('public')->put($media->getPathRelativeToRoot(), metadataTestJpegWithEverything());
    $media->mime_type = 'image/jpeg';
    $media->save();

    Artisan::call('media-library:regenerate', ['--ids' => [$media->getKey()], '--with-responsive-images' => true, '--force' => true]);

    $pathGenerator = PathGeneratorFactory::create($media);
    $derivatives = [
        ...Storage::disk('public')->files($pathGenerator->getPathForConversions($media)),
        ...Storage::disk('public')->files($pathGenerator->getPathForResponsiveImages($media)),
    ];

    expect($derivatives)->not->toBeEmpty()
        ->and(Storage::disk('public')->get($media->getPathRelativeToRoot()))->toContain('TestCam');

    foreach ($derivatives as $path) {
        expect(Storage::disk('public')->get($path))->not->toContain('TestCam');
    }
})->skip(! extension_loaded('imagick'), 'ext-imagick is not installed — GD never copies EXIF, so it would prove nothing');

it('strips derivatives that are already on the disk', function () {
    $item = makeLibraryImage(Tenant::factory()->create());
    $media = $item->getItem();
    $conversions = PathGeneratorFactory::create($media)->getPathForConversions($media);

    // What the Imagick driver used to leave behind.
    Storage::disk('public')->put("{$conversions}legacy-800.jpg", metadataTestJpegWithEverything());
    Storage::disk('public')->put("{$conversions}legacy-400.webp", metadataTestWebp());

    $this->artisan('cms:media:strip-metadata', ['--dry-run' => true])
        ->expectsOutputToContain('Would strip metadata from 2 of')
        ->assertSuccessful();

    expect(Storage::disk('public')->get("{$conversions}legacy-800.jpg"))->toContain('TestCam');

    $this->artisan('cms:media:strip-metadata')
        ->expectsOutputToContain('Stripped metadata from 2 of')
        ->assertSuccessful();

    expect(Storage::disk('public')->get("{$conversions}legacy-800.jpg"))->not->toContain('TestCam')->toContain("ICC_PROFILE\0")
        ->and(webpChunks(Storage::disk('public')->get("{$conversions}legacy-400.webp")))->toBe(['VP8X', 'ICCP', 'VP8 ']);
});

it('rewrites derivatives on a remote disk with the headers the library uploads them with', function () {
    $item = makeLibraryImage(Tenant::factory()->create());
    $media = $item->getItem();
    $path = PathGeneratorFactory::create($media)->getPathForConversions($media).'legacy-800.jpg';
    Storage::disk('public')->put($path, metadataTestJpegWithEverything());

    // Pretend the conversions disk is remote; the fake stays underneath.
    config(['filesystems.disks.public.driver' => 's3']);
    $disk = Mockery::mock(Storage::disk('public'))->makePartial();
    $disk->shouldReceive('put')
        ->once()
        ->withArgs(fn (string $target, string $contents, array $options): bool => $target === $path
            && $options['ContentType'] === 'image/jpeg'
            && $options['CacheControl'] === config('media-library.remote.extra_headers.CacheControl'))
        ->andReturnTrue();
    Storage::set('public', $disk);

    $this->artisan('cms:media:strip-metadata')->assertSuccessful();
});
