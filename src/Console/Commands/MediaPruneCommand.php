<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;

/**
 * Deletes files on the media disk that no media row accounts for. Two
 * independent sources of waste, both of which the library itself never cleans
 * up:
 *
 * 1. SUPERSEDED DERIVATIVES — a conversion's file name is derived from its
 *    current definition, so re-formatting (`jpg` → `webp`) or re-sizing a
 *    conversion writes a NEW file and abandons the old one. Regenerating does
 *    not remove it: the library only knows the name it writes today. Same for
 *    srcset candidates whose step count shrank after a `max_width` cap.
 * 2. ORPHANED DIRECTORIES — an import that was rolled back, or a media row
 *    deleted while the disk was unavailable.
 * 3. AN UNCAPPED ORIGINAL-LEVEL SRCSET — `media-library:regenerate
 *    --with-responsive-images` builds a SECOND candidate set straight off the
 *    original (registered as `media_library_original`), bypassing the
 *    `responsive` conversion and with it both `cms.media.max_width` and
 *    `cms.media.format`. It reads as a reasonable flag and silently doubles
 *    the disk: on a 300-image site it cost 0.8 GB of uncapped JPEGs next to
 *    0.27 GB of capped WebP. `MediaUrlResolver::srcset()` falls back to that
 *    set only when the conversion has none, so once the conversion has its
 *    own candidates the original-level ones are unreachable.
 *
 * Safety model: nothing is deleted that the database does not explicitly
 * disown. Expected file names are computed from the media rows themselves
 * (original + registered conversions + registered responsive candidates), so a
 * row whose conversions are merely NOT YET generated keeps its files. Directory
 * orphans are only considered under the default path generator, where the
 * layout (`{id}/`) is known — a custom generator may map ids to arbitrary
 * paths, and guessing there would delete live files.
 *
 * The load-bearing check is the ORIGINAL: a row only judges its own directory
 * once its original file is found exactly where the row says it is. A disk that
 * has drifted from the database — a `storage/app/public` synced from production
 * against a database from another moment, so directory `16/` holds row 17's
 * file — otherwise reads as "every file is an orphan", and the whole library
 * would be proposed for deletion. Such rows are skipped and counted instead.
 *
 * "Numeric top-level directory" is NOT sufficient to call something a media
 * directory: a WordPress upload tree left behind by `cms:media:import` is
 * numeric too (`2020/01/…`), and a year is indistinguishable from an id. The
 * layout decides instead — a media directory holds its original file plus at
 * most `conversions/` and `responsive-images/`, so anything nesting other
 * subdirectories is left alone and merely REPORTED as a legacy tree for an
 * admin to confirm. Non-library files on the same disk (tenant branding,
 * content-block uploads) are outside the layout and never touched.
 */
class MediaPruneCommand extends Command
{
    protected $signature = 'cms:media:prune
        {--dry-run : Report only — no deletions}
        {--force : Delete without the confirmation prompt}';

    protected $description = 'Delete orphaned files on the media disk: superseded conversions, stale srcset candidates and directories without a media row';

    /**
     * Share of media rows that may fail the original-file anchor before the
     * disk is treated as a different vintage than the database. A few missing
     * originals are broken uploads; a tenth of the library is drift.
     */
    protected const DRIFT_THRESHOLD = 0.1;

    /**
     * The key the library registers candidates under when they are generated
     * off the original rather than off a conversion. Spatie spells it as a
     * literal in half a dozen places and exposes no constant.
     */
    protected const ORIGINAL_LEVEL = 'media_library_original';

    /**
     * The conversion whose candidates the CMS actually renders
     * ({@see MediaUrlResolver::srcset()}).
     */
    protected const RENDERED_CONVERSION = 'responsive';

    /** @var array<int, array{disk: string, path: string, size: int, reason: string}> */
    protected array $orphans = [];

    protected int $scannedMedia = 0;

    protected int $scannedDirectories = 0;

    /** @var array<int, array{path: string, files: int, size: int}> */
    protected array $legacyTrees = [];

    /** @var array<int, int> media ids whose original is not where the row says it is */
    protected array $unverifiedMedia = [];

    /** @var array<int, int> media ids to drop the original-level srcset registration from */
    protected array $supersededOriginalSrcset = [];

    public function handle(): int
    {
        if (! MediaLibrary::enabled()) {
            $this->error('The media library is not available (ralphjsmit/laravel-filament-media-library not installed, or disabled via Cms::disableMediaLibrary()).');

            return self::FAILURE;
        }

        $this->collectStrayFiles();
        $this->collectOrphanedDirectories();

        if ($this->orphans === []) {
            $this->info("Nothing to prune — {$this->scannedMedia} media row(s) and {$this->scannedDirectories} director(ies) are accounted for.");
        } else {
            $this->report();
        }

        $this->reportUnverifiedMedia();
        $this->reportLegacyTrees();

        // A disk that has drifted from the database makes every verdict
        // suspect — including "nothing to prune", which then only means the
        // rows could not be checked at all. Fail in both modes rather than
        // hand back a reading we already know is unreliable.
        if ($this->diskHasDrifted()) {
            $this->error('Refusing to prune: the media disk does not match the database (see above).');

            return self::FAILURE;
        }

        if ($this->orphans === []) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was deleted. Re-run without --dry-run to prune.');

            return self::SUCCESS;
        }

        if (! $this->confirmDeletion()) {
            return self::FAILURE;
        }

        return $this->prune();
    }

    /**
     * Files inside a media row's own directories that the row does not name:
     * conversions left behind by a format/size change and srcset candidates
     * dropped by a narrower width cap.
     */
    protected function collectStrayFiles(): void
    {
        Media::query()->chunkById(200, function ($chunk): void {
            foreach ($chunk as $media) {
                $this->scannedMedia++;

                $pathGenerator = PathGeneratorFactory::create($media);
                $originalDisk = Storage::disk($media->disk);
                $conversionsDisk = Storage::disk($media->conversions_disk ?: $media->disk);

                // Anchor: without its own original in place, the row cannot
                // vouch for anything in that directory — the files may belong
                // to a different row entirely.
                if (! $originalDisk->exists($pathGenerator->getPath($media).$media->file_name)) {
                    $this->unverifiedMedia[] = $media->getKey();

                    continue;
                }

                // The original directory also holds `conversions/` and
                // `responsive-images/`; `files()` is non-recursive, so those
                // stay out of the comparison.
                $this->compare(
                    $media->disk,
                    $originalDisk,
                    $pathGenerator->getPath($media),
                    [$media->file_name],
                    'original directory',
                );

                $conversionsDiskName = $media->conversions_disk ?: $media->disk;

                if (($expected = $this->expectedConversionFiles($media)) !== null) {
                    $this->compare(
                        $conversionsDiskName,
                        $conversionsDisk,
                        $pathGenerator->getPathForConversions($media),
                        $expected,
                        'superseded conversion',
                    );
                }

                $this->compare(
                    $conversionsDiskName,
                    $conversionsDisk,
                    $pathGenerator->getPathForResponsiveImages($media),
                    $this->expectedResponsiveFiles($media),
                    'stale srcset candidate',
                );

                $this->collectSupersededOriginalSrcset(
                    $media,
                    $conversionsDiskName,
                    $conversionsDisk,
                    $pathGenerator->getPathForResponsiveImages($media),
                );
            }
        });
    }

    /**
     * File names the currently REGISTERED conversions would write for this
     * media. Null when the conversions cannot be resolved (model gone, model
     * class removed) — the files are then left alone rather than guessed at.
     *
     * @return array<int, string>|null
     */
    protected function expectedConversionFiles(Media $media): ?array
    {
        try {
            return ConversionCollection::createForMedia($media)
                ->map(fn ($conversion) => $conversion->getConversionFile($media))
                ->values()
                ->all();
        } catch (Throwable $e) {
            $this->warn("Media #{$media->getKey()}: conversions not resolvable ({$e->getMessage()}) — directory skipped.");

            return null;
        }
    }

    /**
     * Candidate file names registered on the media row. The column is keyed by
     * conversion name; `media_library_original` holds candidates generated off
     * the original itself.
     *
     * @return array<int, string>
     */
    protected function expectedResponsiveFiles(Media $media): array
    {
        $registered = $media->responsive_images ?? [];

        $files = [];

        foreach ($registered as $entry) {
            foreach ($entry['urls'] ?? [] as $fileName) {
                $files[] = $fileName;
            }
        }

        return $files;
    }

    /**
     * Candidates generated off the original while the rendered conversion has
     * its own. Unlike the other categories these ARE registered on the row, so
     * the registration is dropped along with the files — otherwise the next run
     * would report them again and the column would point at nothing.
     */
    protected function collectSupersededOriginalSrcset(Media $media, string $diskName, Filesystem $disk, string $directory): void
    {
        $registered = $media->responsive_images ?? [];

        $originalLevel = $registered[static::ORIGINAL_LEVEL]['urls'] ?? [];
        $rendered = $registered[static::RENDERED_CONVERSION]['urls'] ?? [];

        // Without conversion-level candidates the original-level set is what
        // the resolver falls back to — it is the srcset, not a duplicate.
        if ($originalLevel === [] || $rendered === []) {
            return;
        }

        foreach ($originalLevel as $fileName) {
            $path = $directory.$fileName;

            if (! $disk->exists($path)) {
                continue;
            }

            $this->orphans[] = [
                'disk' => $diskName,
                'path' => $path,
                'size' => $this->sizeOf($disk, $path),
                'reason' => 'uncapped original-level srcset',
            ];
        }

        $this->supersededOriginalSrcset[] = $media->getKey();
    }

    /**
     * @param  array<int, string>  $expected
     */
    protected function compare(string $diskName, Filesystem $disk, string $directory, array $expected, string $reason): void
    {
        if (! $disk->directoryExists($directory)) {
            return;
        }

        foreach ($disk->files($directory) as $path) {
            if (in_array(basename($path), $expected, true)) {
                continue;
            }

            $this->orphans[] = [
                'disk' => $diskName,
                'path' => $path,
                'size' => $this->sizeOf($disk, $path),
                'reason' => $reason,
            ];
        }
    }

    /**
     * Media directories with no row behind them. Restricted to the default
     * path generator, whose `{prefix}/{id}` layout makes "numeric directory
     * without a matching id" a safe verdict.
     */
    protected function collectOrphanedDirectories(): void
    {
        $disk = Storage::disk(Cms::mediaDisk());
        $pathGenerator = PathGeneratorFactory::create(new Media);

        if (! $pathGenerator instanceof DefaultPathGenerator) {
            $this->warn('Custom path generator in use — directory orphans are not detected (only the layout of the default generator is known).');

            return;
        }

        $prefix = (string) config('media-library.prefix', '');
        $knownIds = Media::query()->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($disk->directories($prefix) as $directory) {
            $name = basename($directory);

            // Only the library's own `{id}` directories are in scope: tenant
            // branding and content-block uploads share the disk and are none
            // of this command's business.
            if (! ctype_digit($name)) {
                continue;
            }

            if (! $this->hasMediaDirectoryLayout($disk, $directory)) {
                $this->rememberLegacyTree($disk, $directory);

                continue;
            }

            $this->scannedDirectories++;

            if (in_array($name, $knownIds, true)) {
                continue;
            }

            foreach ($disk->allFiles($directory) as $path) {
                $this->orphans[] = [
                    'disk' => Cms::mediaDisk(),
                    'path' => $path,
                    'size' => $this->sizeOf($disk, $path),
                    'reason' => 'directory without media row',
                ];
            }
        }
    }

    /**
     * Whether a numeric directory is shaped like a media directory rather than
     * a legacy `YYYY/MM/` upload tree: the library nests nothing below its own
     * `conversions/` and `responsive-images/`.
     */
    protected function hasMediaDirectoryLayout(Filesystem $disk, string $directory): bool
    {
        foreach ($disk->directories($directory) as $child) {
            if (! in_array(basename($child), ['conversions', 'responsive-images'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A numeric directory that is not shaped like a media directory — almost
     * always the WordPress upload tree `cms:media:import` copies FROM and
     * leaves in place as rollback safety. Reported with its size so the
     * leftovers are visible, never deleted: only an admin can confirm the
     * import is settled.
     */
    protected function rememberLegacyTree(Filesystem $disk, string $directory): void
    {
        $files = $disk->allFiles($directory);

        if ($files === []) {
            return;
        }

        $this->legacyTrees[] = [
            'path' => $directory,
            'files' => count($files),
            'size' => array_sum(array_map(fn (string $path) => $this->sizeOf($disk, $path), $files)),
        ];
    }

    /**
     * A file can vanish between listing and sizing (a queued conversion job
     * rewriting it); an unreadable size must not abort the scan.
     */
    protected function sizeOf(Filesystem $disk, string $path): int
    {
        try {
            return (int) $disk->size($path);
        } catch (Throwable) {
            return 0;
        }
    }

    protected function report(): void
    {
        $byReason = [];

        foreach ($this->orphans as $orphan) {
            $byReason[$orphan['reason']]['count'] = ($byReason[$orphan['reason']]['count'] ?? 0) + 1;
            $byReason[$orphan['reason']]['size'] = ($byReason[$orphan['reason']]['size'] ?? 0) + $orphan['size'];
        }

        $rows = [];

        foreach ($byReason as $reason => $totals) {
            $rows[] = [$reason, $totals['count'], $this->formatBytes($totals['size'])];
        }

        $rows[] = ['<comment>total</comment>', count($this->orphans), $this->formatBytes($this->totalSize())];

        $this->table(['Reason', 'Files', 'Size'], $rows);

        if ($this->output->isVerbose()) {
            foreach ($this->orphans as $orphan) {
                $this->line("  {$orphan['path']} ({$orphan['reason']})");
            }
        }
    }

    /**
     * Surfaces legacy upload trees so "where did the disk go?" does not need a
     * manual `du`, while keeping them out of the deletion set.
     */
    /**
     * Surfaces rows whose original is missing. A handful means broken uploads;
     * a large share means the disk and the database are from different moments,
     * and the run's verdict is not to be trusted at all.
     */
    protected function reportUnverifiedMedia(): void
    {
        if ($this->unverifiedMedia === []) {
            return;
        }

        $count = count($this->unverifiedMedia);

        $this->newLine();
        $this->warn("{$count} of {$this->scannedMedia} media row(s) skipped — their original file is not where the row says it is.");

        if ($this->diskHasDrifted()) {
            $this->warn('That is most of the library: the media disk and the database are probably out of sync (a `storage/app/public` synced separately from the database). Re-sync them before trusting any prune.');
        }
    }

    /**
     * Whether so many rows failed the original anchor that the disk cannot be
     * the one this database describes.
     */
    protected function diskHasDrifted(): bool
    {
        return $this->scannedMedia > 0
            && count($this->unverifiedMedia) / $this->scannedMedia > self::DRIFT_THRESHOLD;
    }

    protected function reportLegacyTrees(): void
    {
        if ($this->legacyTrees === []) {
            return;
        }

        $this->newLine();
        $this->warn('Legacy upload trees found (NOT pruned — delete only once you have confirmed the import is settled):');

        foreach ($this->legacyTrees as $tree) {
            $this->line("  {$tree['path']}/ — {$tree['files']} file(s), ".$this->formatBytes($tree['size']));
        }
    }

    protected function confirmDeletion(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to delete without a confirmation prompt — pass --force (or --dry-run to preview).');

            return false;
        }

        return $this->confirm(
            'Delete '.count($this->orphans).' file(s), freeing '.$this->formatBytes($this->totalSize()).'?',
            false,
        );
    }

    protected function prune(): int
    {
        $deleted = 0;
        $freed = 0;
        $failed = 0;

        foreach ($this->orphans as $orphan) {
            $disk = Storage::disk($orphan['disk']);

            try {
                if ($disk->delete($orphan['path'])) {
                    $deleted++;
                    $freed += $orphan['size'];

                    continue;
                }

                $failed++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Could not delete {$orphan['path']}: {$e->getMessage()}");
            }
        }

        $this->forgetSupersededOriginalSrcset();

        $this->info("Pruned {$deleted} file(s), freed {$this->formatBytes($freed)}.");

        if ($failed > 0) {
            $this->warn("{$failed} file(s) could not be deleted.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Removes the original-level registration from the rows whose candidates
     * were just deleted. Written per row rather than by a mass update: the
     * column is a JSON map whose other keys must survive.
     */
    protected function forgetSupersededOriginalSrcset(): void
    {
        if ($this->supersededOriginalSrcset === []) {
            return;
        }

        Media::query()
            ->whereIn((new Media)->getKeyName(), $this->supersededOriginalSrcset)
            ->each(function (Media $media): void {
                $registered = $media->responsive_images ?? [];

                unset($registered[static::ORIGINAL_LEVEL]);

                $media->responsive_images = $registered;
                $media->save();
            });

        $this->info(count($this->supersededOriginalSrcset).' row(s) no longer register an original-level srcset.');
    }

    protected function totalSize(): int
    {
        return array_sum(array_column($this->orphans, 'size'));
    }

    protected function formatBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
