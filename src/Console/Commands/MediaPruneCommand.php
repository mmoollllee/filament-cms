<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriver;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\PrivateMediaPaths;
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
 * directory: a WordPress upload tree left behind by the media import is
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
        {--force : Delete without the confirmation prompt}
        {--ignore-queue : Prune even while jobs are queued (they may be unrelated)}';

    protected $description = 'Delete orphaned files on the media disk: superseded conversions, stale srcset candidates and directories without a media row';

    /**
     * Share of media rows that may fail the original-file anchor before the
     * disk is treated as a different vintage than the database. A few missing
     * originals are broken uploads; a tenth of the library is drift.
     */
    protected const DRIFT_THRESHOLD = 0.1;

    protected const ORIGINAL_LEVEL = CmsMediaLibraryDriver::ORIGINAL_LEVEL;

    /** @var array<int, array{disk: string, path: string, size: int, reason: string, media_id?: int, directory?: string}> */
    protected array $orphans = [];

    protected int $scannedMedia = 0;

    protected int $scannedDirectories = 0;

    /** @var array<int, array{path: string, files: int, size: int}> */
    protected array $legacyTrees = [];

    /** @var array<int, int> media ids whose original is not where the row says it is */
    protected array $unverifiedMedia = [];

    /** @var array<int, array<int, string>> [media id] => files its original-level registration covers */
    protected array $supersededOriginalSrcsetPaths = [];

    /** @var array<int, array{path: string, files: int, media_id: int}> private directories kept because they may hold a row's only copy */
    protected array $keptDirectories = [];

    public function handle(): int
    {
        if (! MediaLibrary::enabled()) {
            $this->error('The media library is not available (ralphjsmit/laravel-filament-media-library not installed, or disabled via Cms::disableMediaLibrary()).');

            return self::FAILURE;
        }

        if (($pending = $this->pendingConversionJobs()) > 0) {
            $this->error("Refusing to prune: {$pending} queued job(s) are still running.");
            $this->line('Conversions write their file BEFORE registering it, so pruning now deletes derivatives that are about to be recorded — the srcset would keep a permanently dead candidate. Wait for the queue to drain, or pass --ignore-queue if you know these jobs are unrelated.');

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
        $this->reportKeptDirectories();

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
        // The library answers "no conversions" and "I cannot tell you" with the
        // same empty collection: ConversionCollection returns silently when the
        // model class is gone rather than throwing. Reading that as "the row
        // names no conversion files" deletes the whole conversions directory,
        // so the unresolvable case is detected before asking.
        $model = $media->model_type;

        if (blank($model) || (! class_exists($model) && blank(Relation::getMorphedModel($model)))) {
            $this->warn("Media #{$media->getKey()}: model [{$model}] is not resolvable — conversions left alone.");

            return null;
        }

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
        return Arr::flatten(Arr::pluck($media->responsive_images ?? [], 'urls'));
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
        $rendered = $registered[CmsMediaLibraryDriver::RENDERED_CONVERSION]['urls'] ?? [];

        // Without conversion-level candidates the original-level set is what
        // the resolver falls back to — it is the srcset, not a duplicate.
        if ($originalLevel === [] || $rendered === []) {
            return;
        }

        // Registration is not existence. If the conversion-level files are gone
        // — an interrupted prune, a failed regenerate, a partial restore — the
        // set that justifies the deletion is itself dead, and deleting the
        // fallback would leave the row with a srcset of nothing but 404s.
        if (Arr::first($rendered, fn (string $fileName) => $disk->exists($directory.$fileName)) === null) {
            return;
        }

        $paths = [];

        foreach ($originalLevel as $fileName) {
            $path = $directory.$fileName;

            if (! $disk->exists($path)) {
                continue;
            }

            $paths[] = $path;

            $this->orphans[] = [
                'disk' => $diskName,
                'path' => $path,
                'size' => $this->sizeOf($disk, $path),
                'reason' => 'uncapped original-level srcset',
            ];
        }

        if ($paths === []) {
            return;
        }

        $this->supersededOriginalSrcsetPaths[$media->getKey()] = $paths;
    }

    /**
     * @param  array<int, string>  $expected
     */
    protected function compare(string $diskName, Filesystem $disk, string $directory, array $expected, string $reason): void
    {
        // No directoryExists() guard: files() already returns [] for a missing
        // directory on every adapter, and the guard is a second listing —
        // three per media row, each a billed request on S3.
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
        $knownIds = Media::query()->pluck('id')->flip()->all();

        foreach ($disk->directories($prefix) as $directory) {
            $name = basename($directory);

            // A private directory (`{id}-{token}`) is accounted for only while
            // it is its row's CURRENT one: the token is random, so any other
            // is what an interrupted move left behind — or a row that is gone.
            if (preg_match(PrivateMediaPaths::DIRECTORY_PATTERN, $name, $match) === 1) {
                $this->collectPrivateDirectory($disk, $directory, (int) $match[1], isset($knownIds[(int) $match[1]]));

                continue;
            }

            // Only the library's own `{id}` directories are in scope: tenant
            // branding and content-block uploads share the disk and are none
            // of this command's business.
            if (ctype_digit($name)) {
                $this->collectPlainDirectory($disk, $directory, (int) $name, isset($knownIds[(int) $name]));
            }
        }
    }

    /**
     * `{id}/`, the layout before the private library. Accounted for while its
     * row still lives there. Once cms:media:secure moved the row, what stayed
     * behind of the row's own files — a removal that failed — is a leftover;
     * anything else in it stays.
     */
    protected function collectPlainDirectory(Filesystem $disk, string $directory, int $mediaId, bool $isKnown): void
    {
        if (! $this->isMediaDirectory($disk, $directory, hasMediaRow: $isKnown)) {
            return;
        }

        if (! $isKnown) {
            $this->collectDirectoryFiles($disk, $directory, 'directory without media row', $mediaId);

            return;
        }

        $row = Media::query()->find($mediaId);

        if ($row === null || ! $this->movedElsewhere($row, $directory)) {
            return;
        }

        $own = array_filter([$directory.'/'.$row->file_name], $disk->exists(...));

        foreach (['conversions', 'responsive-images'] as $derivatives) {
            array_push($own, ...$disk->allFiles($directory.'/'.$derivatives));
        }

        $this->collectFiles($disk, $own, 'leftover of a moved media', $mediaId, $directory);
    }

    protected function collectPrivateDirectory(Filesystem $disk, string $directory, int $mediaId, bool $isKnown): void
    {
        $row = $isKnown ? Media::query()->find($mediaId) : null;

        // The name alone does not make it the library's — an app folder can
        // look like one (`2024-Betriebsanleitungen`). It is the row's when it
        // holds the row's original; without a row, only the derivative
        // directories a media directory has settle it, as for `{id}/`.
        $holdsRowFiles = $row !== null && $disk->exists($directory.'/'.$row->file_name);

        if (! $this->isMediaDirectory($disk, $directory, hasMediaRow: $holdsRowFiles)) {
            return;
        }

        if ($row === null) {
            $this->collectDirectoryFiles($disk, $directory, 'directory without media row', $mediaId);

            return;
        }

        if (basename(PrivateMediaPaths::baseDirectory($row)) === basename($directory)) {
            return;
        }

        if (! $this->movedElsewhere($row, $directory)) {
            // The row's original is missing where the row says it is: a move
            // cut short may have left the only copy HERE. Kept, and reported.
            $this->keptDirectories[] = [
                'path' => $directory,
                'files' => count($disk->allFiles($directory)),
                'media_id' => $mediaId,
            ];

            return;
        }

        $this->collectDirectoryFiles($disk, $directory, 'superseded private directory', $mediaId);
    }

    /**
     * Whether a directory is shaped like a media directory and so this
     * command's business — counted if so, reported as a legacy tree if not.
     */
    protected function isMediaDirectory(Filesystem $disk, string $directory, bool $hasMediaRow): bool
    {
        if (! $this->hasMediaDirectoryLayout($disk, $directory, $hasMediaRow)) {
            $this->rememberLegacyTree($disk, $directory);

            return false;
        }

        $this->scannedDirectories++;

        return true;
    }

    /**
     * Whether the row lives in another directory than this one, with its
     * original there. Asked now, not taken from the scan: an upload, or
     * cms:media:secure moving an old file, while this command ran puts a row
     * in a directory the scan never saw.
     */
    protected function movedElsewhere(Media $row, string $directory): bool
    {
        $current = PrivateMediaPaths::baseDirectory($row);

        return basename($current) !== basename($directory)
            && Storage::disk($row->disk)->exists($current.'/'.$row->file_name);
    }

    protected function collectDirectoryFiles(Filesystem $disk, string $directory, string $reason, int $mediaId): void
    {
        $this->collectFiles($disk, $disk->allFiles($directory), $reason, $mediaId, $directory);
    }

    /**
     * @param  array<int, string>  $paths
     */
    protected function collectFiles(Filesystem $disk, array $paths, string $reason, int $mediaId, string $directory): void
    {
        foreach ($paths as $path) {
            $this->orphans[] = [
                'disk' => Cms::mediaDisk(),
                'path' => $path,
                'size' => $this->sizeOf($disk, $path),
                'reason' => $reason,
                'media_id' => $mediaId,
                'directory' => $directory,
            ];
        }
    }

    /**
     * Whether a directory listed as an orphan still is one right before its
     * files go: between the listing and the deletion — the
     * confirmation prompt alone can take minutes — cms:media:secure may have
     * moved its row into it, or an upload may have created the row.
     *
     * @param  array{media_id?: int, directory?: string}  $orphan
     * @param  array<string, bool>  $verdicts  per directory, so each is asked once
     */
    protected function stillOrphaned(array $orphan, array &$verdicts): bool
    {
        if (! isset($orphan['media_id'], $orphan['directory'])) {
            return true;
        }

        return $verdicts[$orphan['directory']] ??= (function () use ($orphan): bool {
            $row = Media::query()->find($orphan['media_id']);

            return $row === null || $this->movedElsewhere($row, $orphan['directory']);
        })();
    }

    /**
     * Whether a numeric directory is shaped like a media directory rather than
     * a legacy `YYYY/MM/` upload tree: the library nests nothing below its own
     * `conversions/` and `responsive-images/`.
     *
     * @param  bool  $hasMediaRow  whether a media row carries this directory's id
     */
    protected function hasMediaDirectoryLayout(Filesystem $disk, string $directory, bool $hasMediaRow): bool
    {
        $children = array_map(basename(...), $disk->directories($directory));

        if (array_diff($children, ['conversions', 'responsive-images']) !== []) {
            return false;
        }

        // A derivative directory settles it on its own. Without one there is
        // nothing in the layout to tell `302/logo.svg` from `2019/foto.jpg`, so
        // the media table decides: an SVG or a PDF has no conversions and would
        // otherwise be reported as a legacy tree an admin is invited to delete —
        // which for a logo means deleting it. An id with no row behind it stays
        // reported rather than pruned, so a year folder is still spared.
        return $children !== [] || $hasMediaRow;
    }

    /**
     * A numeric directory that is not shaped like a media directory — almost
     * always the WordPress upload tree the media import copied FROM and
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
        $rows = collect($this->orphans)
            ->groupBy('reason')
            ->map(fn ($group, $reason) => [$reason, $group->count(), $this->formatBytes((int) $group->sum('size'))])
            ->values()
            ->all();

        $rows[] = ['<comment>total</comment>', count($this->orphans), $this->formatBytes($this->totalSize())];

        $this->table(['Reason', 'Files', 'Size'], $rows);

        if ($this->output->isVerbose()) {
            foreach ($this->orphans as $orphan) {
                $this->line("  {$orphan['path']} ({$orphan['reason']})");
            }
        }
    }

    /**
     * Queued jobs still to run. Conversions write their file before recording
     * it, so a prune overlapping a regenerate deletes a derivative in the window
     * between the two — and the job then registers a name that no longer exists.
     */
    protected function pendingConversionJobs(): int
    {
        if ($this->option('ignore-queue')) {
            return 0;
        }

        try {
            return (int) Queue::size(config('media-library.queue_name') ?: null);
        } catch (Throwable) {
            // A queue this command cannot inspect (sync, or a driver without a
            // size) is not a reason to refuse.
            return 0;
        }
    }

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
        // No media rows at all, yet the disk holds media-shaped directories:
        // the most complete drift there is. The `> 0` guard below only dodges a
        // division by zero, and reading it as "no rows, no drift" would let a
        // run against an empty or wrong database delete the entire library.
        if ($this->scannedMedia === 0) {
            return $this->scannedDirectories > 0;
        }

        return count($this->unverifiedMedia) / $this->scannedMedia > self::DRIFT_THRESHOLD;
    }

    /**
     * Surfaces legacy upload trees so "where did the disk go?" does not need a
     * manual `du`, while keeping them out of the deletion set.
     */
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

    protected function reportKeptDirectories(): void
    {
        if ($this->keptDirectories === []) {
            return;
        }

        $this->newLine();
        $this->warn('Private directories kept (NOT pruned) — their row\'s original is missing from its current directory, so a move may have been cut short and left the only copy here:');

        foreach ($this->keptDirectories as $kept) {
            $this->line("  {$kept['path']}/ — {$kept['files']} file(s), media #{$kept['media_id']}");
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

        /** @var array<string, true> */
        $deletedPaths = [];

        /** @var array<string, bool> */
        $verdicts = [];
        $skipped = 0;

        foreach ($this->orphans as $orphan) {
            if (! $this->stillOrphaned($orphan, $verdicts)) {
                $skipped++;

                continue;
            }

            $disk = Storage::disk($orphan['disk']);

            try {
                if ($disk->delete($orphan['path'])) {
                    $deleted++;
                    $freed += $orphan['size'];
                    $deletedPaths[$orphan['path']] = true;

                    continue;
                }

                $failed++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Could not delete {$orphan['path']}: {$e->getMessage()}");
            }
        }

        // Only for rows whose candidates actually left the disk. Dropping the
        // registration after a failed delete strands the files AND loses the
        // fallback srcset, which no rerun can rebuild.
        $this->forgetSupersededOriginalSrcset($deletedPaths);

        $this->info("Pruned {$deleted} file(s), freed {$this->formatBytes($freed)}.");

        if ($skipped > 0) {
            $this->warn("{$skipped} file(s) kept: their directory became a media row's current one while this command ran.");
        }

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
     *
     * @param  array<string, true>  $deletedPaths
     */
    protected function forgetSupersededOriginalSrcset(array $deletedPaths): void
    {
        // Only rows whose candidates ALL left the disk: a partial delete that
        // still dropped the registration would strand the files and lose the
        // fallback srcset with them.
        $ids = [];

        foreach ($this->supersededOriginalSrcsetPaths as $id => $paths) {
            if (array_diff_key(array_flip($paths), $deletedPaths) === []) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return;
        }

        Media::query()
            ->whereKey($ids)
            ->each(function (Media $media): void {
                $registered = $media->responsive_images ?? [];

                unset($registered[static::ORIGINAL_LEVEL]);

                $media->responsive_images = $registered;
                $media->save();
            });

        $this->info(count($ids).' row(s) no longer register an original-level srcset.');
    }

    protected function totalSize(): int
    {
        return array_sum(array_column($this->orphans, 'size'));
    }

    protected function formatBytes(int $bytes): string
    {
        return Number::fileSize($bytes, precision: 1);
    }
}
