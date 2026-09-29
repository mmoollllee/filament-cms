<?php

namespace Mmoollllee\Cms\Console\Commands;

use finfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Mmoollllee\Cms\Support\Media\ImageMetadata;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MetadataFreeFilesystem;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

/**
 * Strips camera metadata from the derivatives already on the media disk — conversions and
 * srcset candidates — the way {@see MetadataFreeFilesystem} strips new ones, then hands the
 * blurred placeholders to `cms:media:placeholders`.
 *
 * Lossless and cheap: the files are rewritten without their EXIF/XMP/IPTC blocks, not
 * regenerated, so nothing is resized or re-encoded and no queue is involved. Originals are
 * never touched.
 */
class MediaStripMetadataCommand extends Command
{
    protected $signature = 'cms:media:strip-metadata
        {--dry-run : Report only — change nothing}';

    protected $description = 'Remove camera metadata (GPS, device, embedded previews) from conversions, srcset candidates and placeholders';

    public function handle(): int
    {
        if (! MediaLibrary::enabled()) {
            $this->error('The media library is not available.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $scanned = 0;
        $stripped = 0;
        $saved = 0;

        $mediaModel = config('media-library.media_model', Media::class);

        foreach ($mediaModel::query()->lazyById() as $media) {
            $disk = Storage::disk($media->conversions_disk ?: $media->disk);
            $pathGenerator = PathGeneratorFactory::create($media);

            $files = [
                ...$disk->files($pathGenerator->getPathForConversions($media)),
                ...$disk->files($pathGenerator->getPathForResponsiveImages($media)),
            ];

            foreach ($files as $path) {
                $scanned++;
                $bytes = (string) $disk->get($path);
                $clean = ImageMetadata::strip($bytes);

                if ($clean === $bytes) {
                    continue;
                }

                $stripped++;
                $saved += strlen($bytes) - strlen($clean);

                if ($dryRun) {
                    $this->line("Would strip {$path}");

                    continue;
                }

                $disk->put($path, $clean, $this->uploadOptions($media, $clean));
            }
        }

        $verb = $dryRun ? 'Would strip' : 'Stripped';
        $this->info("{$verb} metadata from {$stripped} of {$scanned} derivative file(s), ".Number::fileSize($saved).'.');

        return $this->call('cms:media:placeholders', $dryRun ? ['--dry-run' => true] : []);
    }

    /**
     * The headers the library itself uploads derivatives with. A bare put() on a remote disk
     * would drop them: the disk's default visibility (often private), no Content-Type, no
     * Cache-Control. Local disks take none.
     *
     * @return array<string, mixed>
     */
    protected function uploadOptions(Media $media, string $bytes): array
    {
        if ($media->getConversionsDiskDriverName() === 'local') {
            return [];
        }

        $customHeaders = $media->getCustomHeaders();
        unset($customHeaders['ContentType']);

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: null;

        return app(Filesystem::class)->getRemoteHeadersForFile('', $customHeaders, $mimeType);
    }
}
