<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Support\Media\BlurredPlaceholderGenerator;
use Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriver;
use Mmoollllee\Cms\Support\Media\ImageMetadata;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Throwable;

/**
 * Rebuilds the blurred placeholders of the responsive images — and only those: the srcset
 * candidates and conversions stay as they are.
 *
 * By default it touches the placeholders that are missing or still carry the metadata the
 * stock generator copied over ({@see BlurredPlaceholderGenerator}); `--all` rebuilds every one.
 * Each placeholder is generated from the same file the library generated it from: the
 * conversion's file, or the original for original-level responsive images.
 */
class MediaPlaceholdersCommand extends Command
{
    protected $signature = 'cms:media:placeholders
        {--all : Rebuild every placeholder, not only missing or metadata-laden ones}
        {--dry-run : Report only — change nothing}';

    protected $description = 'Rebuild the blurred placeholders of responsive images that are missing or carry metadata';

    public function handle(ResponsiveImageGenerator $generator): int
    {
        if (! MediaLibrary::enabled()) {
            $this->error('The media library is not available.');

            return self::FAILURE;
        }

        if (! config('media-library.responsive_images.use_tiny_placeholders')) {
            $this->info('Placeholders are turned off (media-library.responsive_images.use_tiny_placeholders).');

            return self::SUCCESS;
        }

        $scanned = 0;
        $rebuilt = 0;
        $failed = 0;

        $mediaModel = config('media-library.media_model', Media::class);

        foreach ($mediaModel::query()->lazyById() as $media) {
            if (! MediaUrlResolver::isProcessableImageMime($media->mime_type)) {
                continue;
            }

            foreach (array_keys((array) $media->responsive_images) as $conversion) {
                $scanned++;

                if (! $this->option('all') && ! $this->needsRebuild($media, $conversion)) {
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("Would rebuild #{$media->getKey()} {$media->file_name} ({$conversion})");
                    $rebuilt++;

                    continue;
                }

                try {
                    $this->rebuild($generator, $media, $conversion);
                    $rebuilt++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->warn("#{$media->getKey()} {$media->file_name} ({$conversion}): {$exception->getMessage()}");
                }
            }
        }

        $verb = $this->option('dry-run') ? 'Would rebuild' : 'Rebuilt';
        $this->info("{$verb} {$rebuilt} of {$scanned} placeholder(s).".($failed > 0 ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function needsRebuild(Media $media, string $conversion): bool
    {
        $svg = $media->responsive_images[$conversion]['base64svg'] ?? null;

        if (blank($svg)) {
            return true;
        }

        $markup = (string) base64_decode(substr($svg, strpos($svg, ',') + 1));

        if (preg_match('#data:image/jpeg;base64,([A-Za-z0-9+/=]+)#', $markup, $match) !== 1) {
            return true;
        }

        return ImageMetadata::has((string) base64_decode($match[1]), keepColorProfile: false);
    }

    protected function rebuild(ResponsiveImageGenerator $generator, Media $media, string $conversion): void
    {
        [$disk, $path] = $conversion === CmsMediaLibraryDriver::ORIGINAL_LEVEL
            ? [$media->disk, $media->getPathRelativeToRoot()]
            : [$media->conversions_disk ?: $media->disk, $media->getPathRelativeToRoot($conversion)];

        $stream = Storage::disk($disk)->readStream($path);

        if ($stream === null) {
            throw new RuntimeException("source file missing: {$path}");
        }

        $temporaryDirectory = TemporaryDirectory::create();

        try {
            $source = $temporaryDirectory->path('source.'.pathinfo($path, PATHINFO_EXTENSION));
            file_put_contents($source, $stream);

            $generator->generateTinyJpg($media, $source, $conversion, $temporaryDirectory);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            $temporaryDirectory->delete();
        }
    }
}
