<?php

namespace Mmoollllee\Cms\Support\Media;

use FFMpeg\Format\Video\X264;
use Mmoollllee\Cms\Support\Content\VideoConversionHelper;
use ProtoneMedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The one ffmpeg recipe for web video: H.264 in an MP4 container, at most
 * 1920 px wide, `faststart` so playback begins before the download ends, AAC
 * audio unless it is dropped.
 *
 * Shared by the upload-time conversion of library videos
 * ({@see \Mmoollllee\Cms\Jobs\ConvertLibraryVideo}) and the legacy path-based
 * pipeline ({@see \Mmoollllee\Cms\Jobs\ConvertVideoForWeb}). Resolved through
 * the container, so tests swap in a fake that needs no ffmpeg binary.
 */
class VideoTranscoder
{
    /** Widest picture the recipe keeps; wider videos are scaled down to it. */
    public const MAX_WIDTH = 1920;

    /**
     * What ffprobe says about a library file: the codec and width of its
     * first video stream and its overall bitrate in bits per second — null
     * where it does not say. A file on a remote disk is fetched first; the
     * caller removes laravel-ffmpeg's temporary directories.
     *
     * @return array{codec: ?string, width: ?int, bitrate: ?int}
     */
    public function probe(Media $media): array
    {
        $opened = FFMpeg::fromDisk($media->disk)->open($media->getPathRelativeToRoot());
        $video = $opened->getVideoStream();
        $codec = $video?->get('codec_name');
        $width = $video?->get('width');
        $bitrate = $opened->getFormat()->get('bit_rate');

        return [
            'codec' => is_string($codec) && $codec !== '' ? strtolower($codec) : null,
            'width' => is_numeric($width) ? (int) $width : null,
            'bitrate' => is_numeric($bitrate) ? (int) $bitrate : null,
        ];
    }

    /**
     * Re-encode a library media file into a fresh local MP4.
     *
     * @return string absolute path of the MP4, in a temporary directory of its
     *                own that the caller removes once the file has been used
     */
    public function toWebMp4(Media $media, string $quality, bool $keepAudio = true): string
    {
        $directory = app(TemporaryDirectories::class)->create();

        FFMpeg::fromDisk($media->disk)
            ->open($media->getPathRelativeToRoot())
            ->export()
            ->toDisk(app('filesystem')->createLocalDriver(['root' => $directory]))
            ->inFormat(static::format($quality, $keepAudio))
            ->save('web.mp4');

        return $directory.'/web.mp4';
    }

    public static function format(string $quality, bool $keepAudio = true): X264
    {
        $format = (new X264)
            ->setKiloBitrate(0)
            ->setAdditionalParameters([
                '-crf', (string) VideoConversionHelper::crfForQuality($quality),
                '-preset', 'faster',
                '-movflags', '+faststart',
                '-vf', 'scale=min('.self::MAX_WIDTH.'\\,iw):-2',
            ]);

        if (! $keepAudio) {
            return $format->setAdditionalParameters([
                ...$format->getAdditionalParameters(),
                '-an',
            ]);
        }

        return $format
            ->setAudioCodec('aac')
            ->setAudioKiloBitrate(128);
    }
}
