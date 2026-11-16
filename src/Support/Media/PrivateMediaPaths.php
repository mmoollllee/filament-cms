<?php

namespace Mmoollllee\Cms\Support\Media;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use LogicException;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;

/**
 * The private media library: a file nobody has been shown cannot be found by
 * trying links.
 *
 * The library stores files under `{media-id}/` by default, so every upload —
 * the draft of an unannounced product, a document meant for one page that is
 * not live yet — can be found by counting up ids. Here each file gets a
 * directory with a random part instead, `{media-id}-{token}/`, which nobody
 * can guess, and keeps it for good: publishing, taking a page offline or
 * swapping an image moves nothing. A link becomes known once a page shows the
 * file and stays valid after that — this hides what was never shown, it does
 * not take back what was.
 *
 * The TOKEN (the random part of the directory) is kept on the Spatie media
 * row as a custom property, so it travels with the file.
 *
 * The path generator is registered for library media only, and only while
 * the app runs Spatie's default generator — an app with its own layout keeps
 * it. Media without a token keep the plain `{id}/` directory, so switching
 * this on (or off again) never loses a file; `cms:media:secure` moves the old
 * ones once ({@see LegacyMediaPaths}).
 */
final class PrivateMediaPaths
{
    public const TOKEN = 'cms_path_token';

    public const TOKEN_LENGTH = 24;

    /** What a token may look like — anything else must never reach a path. */
    private const TOKEN_CHARACTERS = '[A-Za-z0-9]{16,64}';

    /** A private directory's name: the media id, a dash, the token. */
    public const DIRECTORY_PATTERN = '/^(\d+)-'.self::TOKEN_CHARACTERS.'$/';

    /** What a media directory holds besides the original itself. */
    private const DERIVATIVE_DIRECTORIES = ['conversions', 'responsive-images'];

    private static bool $registered = false;

    /**
     * Route library media through {@see PrivateMediaPathGenerator} — unless
     * the app runs a path generator of its own. Always on when it can be: a
     * file that has a token must be found under it even after an app turned
     * new tokens off.
     */
    public static function registerPathGenerator(): void
    {
        self::$registered = false;

        if (config('media-library.path_generator') !== DefaultPathGenerator::class) {
            return;
        }

        foreach (array_keys((array) config('media-library.custom_path_generators', [])) as $key) {
            if (self::coversLibraryItems((string) $key)) {
                return;
            }
        }

        PathGeneratorFactory::setCustomPathGenerators(MediaLibrary::ITEM_MORPH_ALIAS, PrivateMediaPathGenerator::class);
        PathGeneratorFactory::setCustomPathGenerators(MediaLibraryItem::class, PrivateMediaPathGenerator::class);

        self::$registered = true;
    }

    /** Whether new library files get a private directory. */
    public static function enabled(): bool
    {
        return self::$registered && MediaLibrary::enabled() && (bool) config('cms.media.private', true);
    }

    /** Whether the generator is in place (tokens that exist are honored). */
    public static function generatorRegistered(): bool
    {
        return self::$registered;
    }

    public static function token(Media $media): ?string
    {
        $token = $media->getCustomProperty(self::TOKEN);

        // Anything else would end up in a path — never trust it there.
        return is_string($token) && preg_match('/^'.self::TOKEN_CHARACTERS.'$/', $token) === 1 ? $token : null;
    }

    public static function newToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    /**
     * Listener for the media model's `creating`: every new library file gets
     * a directory of its own — a replacement of an item's file, and a copy
     * whose properties carry another file's token, too.
     */
    public static function prepareNewMedia(Media $media): void
    {
        if (! self::enabled() || ! MediaLibrary::isLibraryMedia($media)) {
            return;
        }

        $media->setCustomProperty(self::TOKEN, self::newToken());
    }

    /**
     * Move a file stored before the private library — original, conversions,
     * srcset candidates — out of its guessable `{id}/` into a directory of
     * its own. Done once per file, by `cms:media:secure`: a file that has a
     * token never moves again.
     *
     * The files are put in place first and the row is pointed at them second;
     * if a copy fails or the row cannot be saved, they go back and the row
     * keeps its directory. A crash in between leaves the files in a directory
     * the row does not name — `cms:media:prune` keeps such a directory and
     * reports it, because the row's own original is missing.
     *
     * @return bool whether anything moved: false for a file that has a directory of its own already, or no files to move — its row stays as it is
     */
    public static function moveToPrivateDirectory(Media $media): bool
    {
        if (self::token($media) !== null) {
            return false;
        }

        $from = self::baseDirectory($media);

        $media->setCustomProperty(self::TOKEN, self::newToken());
        $to = self::baseDirectory($media);

        // Only ever move between directories this class lays out. Under an
        // app's own generator both paths are the same — and "moving" would
        // copy the files onto themselves, then delete them.
        if ($from === $to || ! PathGeneratorFactory::create($media) instanceof PrivateMediaPathGenerator) {
            $media->forgetCustomProperty(self::TOKEN);

            throw new LogicException("Media [{$media->getKey()}] is not stored by the private path generator; its directory stays where it is.");
        }

        $disks = array_values(array_unique(array_filter([$media->disk, $media->conversions_disk ?: $media->disk])));
        $moved = [];

        try {
            foreach ($disks as $disk) {
                if (($move = self::moveDirectory(Storage::disk($disk), $from, $to, $media)) !== null) {
                    $moved[$disk] = $move;
                }
            }

            // Nothing on disk to move: a token would point the row at a
            // directory that does not exist.
            if ($moved === []) {
                $media->forgetCustomProperty(self::TOKEN);

                return false;
            }

            $media->saveQuietly();
        } catch (Throwable $exception) {
            foreach ($moved as $disk => $move) {
                rescue(fn () => self::undoMove(Storage::disk($disk), $from, $to, $move), report: false);
            }

            $media->forgetCustomProperty(self::TOKEN);

            throw $exception;
        }

        foreach ($moved as $disk => $move) {
            self::removeSource(Storage::disk($disk), $from, $move);
        }

        return true;
    }

    /** The media's directory as its path generator computes it, without the trailing slash. */
    public static function baseDirectory(Media $media): string
    {
        return rtrim(PathGeneratorFactory::create($media)->getPath($media), '/');
    }

    /**
     * Whether a key of Spatie's `custom_path_generators` covers library media:
     * the plugin's alias, its item model or an app's subclass of it, or a class
     * or interface the item model extends — an app layout either way.
     */
    private static function coversLibraryItems(string $key): bool
    {
        if ($key === MediaLibrary::ITEM_MORPH_ALIAS) {
            return true;
        }

        if (! class_exists($key) && ! interface_exists($key)) {
            return false;
        }

        return is_a($key, MediaLibraryItem::class, true) || is_a(MediaLibraryItem::class, $key, true);
    }

    /**
     * Put the media's files under `$to`. A directory that holds nothing but
     * them moves whole — on a local disk with one rename, atomic and instant
     * even for a 128 MB video. One that holds more (a legacy `{id}/` can share
     * its name with an imported year folder, `2020/01/…`) gives up only the
     * media's own files.
     *
     * @return array{how: 'renamed'|'copied', remove: list<string>}|null null when there is nothing to move
     */
    private static function moveDirectory(FilesystemAdapter $disk, string $from, string $to, Media $media): ?array
    {
        if (! $disk->directoryExists($from)) {
            return null;
        }

        $own = self::ownEntries($disk, $from, $media);
        $files = [];

        foreach ($own ?? [$from] as $entry) {
            array_push($files, ...($disk->directoryExists($entry) ? $disk->allFiles($entry) : [$entry]));
        }

        if ($files === []) {
            return null;
        }

        if ($own === null && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $target = $disk->path($to);

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            if (! file_exists($target) && @rename($disk->path($from), $target)) {
                return ['how' => 'renamed', 'remove' => []];
            }
        }

        try {
            foreach ($files as $path) {
                $target = $to.substr($path, strlen($from));

                // A disk that does not throw reports a failed copy only here —
                // carrying on would delete the one copy there is.
                if (! $disk->copy($path, $target)) {
                    throw new RuntimeException("Could not copy [{$path}] to [{$target}]; the media keeps its directory.");
                }
            }
        } catch (Throwable $exception) {
            $disk->deleteDirectory($to);

            throw $exception;
        }

        return ['how' => 'copied', 'remove' => $own ?? [$from]];
    }

    /**
     * The media's own entries in its directory — the original and the
     * derivative directories — when the directory also holds something that is
     * not the media's; null when it holds nothing else.
     *
     * @return list<string>|null
     */
    private static function ownEntries(FilesystemAdapter $disk, string $from, Media $media): ?array
    {
        $files = array_map(basename(...), $disk->files($from));
        $directories = array_map(basename(...), $disk->directories($from));

        if (array_diff($files, [(string) $media->file_name]) === [] && array_diff($directories, self::DERIVATIVE_DIRECTORIES) === []) {
            return null;
        }

        return [
            ...(in_array((string) $media->file_name, $files, true) ? [$from.'/'.$media->file_name] : []),
            ...array_map(fn (string $directory): string => $from.'/'.$directory, array_values(array_intersect($directories, self::DERIVATIVE_DIRECTORIES))),
        ];
    }

    /**
     * @param  array{how: 'renamed'|'copied', remove: list<string>}  $move
     */
    private static function removeSource(FilesystemAdapter $disk, string $from, array $move): void
    {
        foreach ($move['remove'] as $path) {
            $removed = $disk->directoryExists($path) ? $disk->deleteDirectory($path) : $disk->delete($path);

            if (! $removed) {
                report(new RuntimeException("Moved media files out of [{$from}], but could not remove [{$path}] — its old link works until it is removed."));
            }
        }
    }

    /**
     * @param  array{how: 'renamed'|'copied', remove: list<string>}  $move
     */
    private static function undoMove(FilesystemAdapter $disk, string $from, string $to, array $move): void
    {
        if ($move['how'] === 'renamed') {
            rename($disk->path($to), $disk->path($from));

            return;
        }

        $disk->deleteDirectory($to);
    }
}
