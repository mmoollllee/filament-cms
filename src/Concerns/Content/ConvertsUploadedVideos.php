<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Mmoollllee\Cms\Jobs\ConvertVideoForWeb;
use Mmoollllee\Cms\Support\Content\VideoConversionHelper;

/**
 * Dispatches {@see ConvertVideoForWeb} for every media block whose upload needs
 * a web-friendly re-encode (non-MP4 container or oversized MP4), whenever the
 * model's `blocks` change. The job transcodes to H.264 MP4 in the configured
 * quality and swaps the block's media_path when done.
 */
trait ConvertsUploadedVideos
{
    public static function bootConvertsUploadedVideos(): void
    {
        static::saved(function ($content): void {
            if (! $content->wasRecentlyCreated && ! $content->wasChanged('blocks')) {
                return;
            }

            foreach ($content->blocks ?? [] as $section) {
                $mediaBlocks = ($section['type'] ?? '') === 'section'
                    ? ($section['data']['blocks'] ?? [])
                    : [['type' => $section['type'] ?? '', 'data' => $section['data'] ?? []]];

                foreach ($mediaBlocks as $block) {
                    if (($block['type'] ?? '') !== 'media') {
                        continue;
                    }

                    $blockData = $block['data'] ?? [];

                    if (VideoConversionHelper::needsConversion($blockData)) {
                        ConvertVideoForWeb::dispatch(
                            $content->getKey(),
                            $blockData['media_path'],
                        );
                    }
                }
            }
        });
    }
}
