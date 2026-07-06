<?php

namespace Mmoollllee\Cms\Jobs;

use FFMpeg\Format\Video\X264;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Support\Content\VideoConversionHelper;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Throwable;

class ConvertVideoForWeb implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(
        public int $contentId,
        public string $mediaPath,
    ) {}

    public function handle(): void
    {
        $content = Cms::contentModel()::find($this->contentId);

        if (! $content) {
            return;
        }

        $blockInfo = $this->findMediaBlock($content);

        if ($blockInfo === null) {
            return;
        }

        [$sectionIndex, $blockIndex, $blockData] = $blockInfo;

        $disk = 'public';

        if (! Storage::disk($disk)->exists($this->mediaPath)) {
            return;
        }

        // Mark as processing
        $this->updateBlockData($content, $sectionIndex, $blockIndex, [
            'video_conversion_status' => 'processing',
        ]);

        $quality = $blockData['video_quality'] ?? 'medium';
        $keepAudio = $blockData['video_keep_audio'] ?? true;

        $crf = VideoConversionHelper::crfForQuality($quality);
        $outputPath = $this->buildOutputPath($this->mediaPath);

        $format = (new X264)
            ->setKiloBitrate(0)
            ->setAdditionalParameters([
                '-crf', (string) $crf,
                '-preset', 'faster',
                '-movflags', '+faststart',
                '-vf', 'scale=min(1920\\,iw):-2',
            ]);

        if (! $keepAudio) {
            $format->setAdditionalParameters([
                ...$format->getAdditionalParameters(),
                '-an',
            ]);
        } else {
            $format->setAudioCodec('aac')
                ->setAudioKiloBitrate(128);
        }

        FFMpeg::fromDisk($disk)
            ->open($this->mediaPath)
            ->export()
            ->toDisk($disk)
            ->inFormat($format)
            ->save($outputPath);

        $finalPath = $this->buildFinalPath($this->mediaPath);

        // Put the converted file in place BEFORE removing the source, so a move that fails
        // (disk full, permissions, name clash) never leaves us with neither file. The temp
        // always has a distinct "-converting.mp4" name, so this guard is defensive only.
        if ($outputPath !== $finalPath) {
            Storage::disk($disk)->move($outputPath, $finalPath);
        }

        // Only drop the original once the converted file verifiably exists at its final path.
        if ($this->mediaPath !== $finalPath && Storage::disk($disk)->exists($finalPath)) {
            Storage::disk($disk)->delete($this->mediaPath);
        }

        // Update block data: set converted flag, update path, clean up options
        $this->updateBlockData($content, $sectionIndex, $blockIndex, [
            'media_path' => $finalPath,
            'video_converted' => true,
            'video_quality' => null,
            'video_keep_audio' => null,
            'video_conversion_status' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Video conversion failed', [
            'content_id' => $this->contentId,
            'media_path' => $this->mediaPath,
            'error' => $exception->getMessage(),
        ]);

        $content = Cms::contentModel()::find($this->contentId);

        if (! $content) {
            return;
        }

        $blockInfo = $this->findMediaBlock($content);

        if ($blockInfo === null) {
            return;
        }

        [$sectionIndex, $blockIndex] = $blockInfo;

        $this->updateBlockData($content, $sectionIndex, $blockIndex, [
            'video_conversion_status' => 'failed',
        ]);

        // Clean up temp file if it exists
        $outputPath = $this->buildOutputPath($this->mediaPath);
        Storage::disk('public')->delete($outputPath);
    }

    /**
     * Find the media block matching this job's mediaPath.
     *
     * @return array{0: int, 1: int, 2: array<string, mixed>}|null
     */
    private function findMediaBlock(Content $content): ?array
    {
        foreach ($content->blocks ?? [] as $sectionIndex => $section) {
            if (($section['type'] ?? '') !== 'section') {
                // Handle top-level media blocks too
                if (($section['type'] ?? '') === 'media' && ($section['data']['media_path'] ?? null) === $this->mediaPath) {
                    return [$sectionIndex, -1, $section['data']];
                }

                continue;
            }

            foreach ($section['data']['blocks'] ?? [] as $blockIndex => $block) {
                if (($block['type'] ?? '') === 'media' && ($block['data']['media_path'] ?? null) === $this->mediaPath) {
                    return [$sectionIndex, $blockIndex, $block['data']];
                }
            }
        }

        return null;
    }

    /**
     * Update specific keys in the media block's data.
     *
     * @param  array<string, mixed>  $updates
     */
    private function updateBlockData(Content $content, int $sectionIndex, int $blockIndex, array $updates): void
    {
        $blocks = $content->blocks;

        if ($blockIndex === -1) {
            // Top-level media block
            foreach ($updates as $key => $value) {
                if ($value === null) {
                    unset($blocks[$sectionIndex]['data'][$key]);
                } else {
                    $blocks[$sectionIndex]['data'][$key] = $value;
                }
            }
        } else {
            // Nested inside section
            foreach ($updates as $key => $value) {
                if ($value === null) {
                    unset($blocks[$sectionIndex]['data']['blocks'][$blockIndex]['data'][$key]);
                } else {
                    $blocks[$sectionIndex]['data']['blocks'][$blockIndex]['data'][$key] = $value;
                }
            }
        }

        $content->blocks = $blocks;
        $content->saveQuietly();
    }

    /**
     * Build the temporary output path for conversion.
     */
    private function buildOutputPath(string $originalPath): string
    {
        $info = pathinfo($originalPath);

        return $info['dirname'].'/'.$info['filename'].'-converting.mp4';
    }

    /**
     * Build the final path (same name, but .mp4 extension).
     */
    private function buildFinalPath(string $originalPath): string
    {
        $info = pathinfo($originalPath);

        return $info['dirname'].'/'.$info['filename'].'.mp4';
    }
}
