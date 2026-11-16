<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Support\Media\LegacyMediaPaths;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\PrivateMediaPaths;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Moves the files stored before the private library existed out of their
 * guessable `{id}/`, and rewrites the links stored as text to them
 * ({@see LegacyMediaPaths}). Run by hand once after updating; files a
 * published page shows keep their link — run it again later to move those
 * once no page shows them.
 *
 * Whatever moves loses its old address, links from other sites included. So
 * the dry run lists every file it would move with that address — to check
 * against the web server's access log — and `--keep` leaves named files
 * where they are.
 */
class MediaSecureCommand extends Command
{
    protected $signature = 'cms:media:secure
        {--tenant= : Only this tenant (id or site key)}
        {--keep= : Media ids to leave where they are, comma-separated — a file another site links to}
        {--dry-run : List what would move and change, move and rewrite nothing}';

    protected $description = 'Move media-library files stored before the private library to unguessable directories, and rewrite links to them';

    /** Longer than any run takes; frees the lock should a run die. */
    private const LOCK_SECONDS = 3600;

    public function handle(): int
    {
        if (! MediaLibrary::enabled()) {
            $this->error('The media library is not available (ralphjsmit/laravel-filament-media-library not installed, or disabled via Cms::disableMediaLibrary()).');

            return self::FAILURE;
        }

        if (! PrivateMediaPaths::generatorRegistered()) {
            $this->warn('The app uses a path generator of its own — the private media library stays off.');

            return self::SUCCESS;
        }

        if (! PrivateMediaPaths::enabled()) {
            $this->info('The private media library is switched off (cms.media.private).');

            return self::SUCCESS;
        }

        $keep = $this->keep();

        if ($keep === null) {
            $this->error('--keep takes media ids, comma-separated: --keep=12,15');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            return $this->secure(dryRun: true, keep: $keep);
        }

        // Two runs moving the same file would each copy it, point the row at
        // one copy and the links at either.
        $lock = Cache::lock('cms:media:secure', self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->error('Another cms:media:secure run is moving files — try again once it has finished.');

            return self::FAILURE;
        }

        try {
            return $this->secure(dryRun: false, keep: $keep);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<int>  $keep
     */
    private function secure(bool $dryRun, array $keep): int
    {
        $rows = [];
        $moved = [];
        $tenantOf = [];
        $failed = 0;

        foreach ($this->tenants() as $tenant) {
            $label = (string) ($tenant->getAttribute('site_key') ?? $tenant->getKey());
            $result = LegacyMediaPaths::secure($tenant, dryRun: $dryRun, keep: $keep);

            array_push($moved, ...$result['moved']);
            $tenantOf += array_fill_keys($result['moved'], $label);
            $failed += $result['failed'];

            $rows[] = [$label, count($result['moved']), $result['kept'], $result['held'], $result['failed']];
        }

        $this->table(['Tenant', $dryRun ? 'Would move' : 'Moved', 'Kept (a published page shows it)', 'Kept (--keep)', 'Failed'], $rows);

        if ($dryRun && $moved !== []) {
            $this->newLine();
            $this->line('These files would move, and their old address would stop working. Check the addresses against the web server\'s access log; keep a file another site links to with --keep=<media id>.');
            $this->table(['Tenant', 'Media', 'Old address'], $this->oldAddresses($moved, $tenantOf));
        }

        $links = LegacyMediaPaths::rewriteLinks(dryRun: $dryRun, pending: $dryRun ? $moved : []);

        if ($links > 0) {
            $this->info($dryRun
                ? "{$links} record(s) link a file by its old URL — they would be rewritten."
                : "{$links} record(s) linked a file by its old URL — rewritten.");
        }

        if ($dryRun) {
            $this->info('Dry run — nothing was moved or rewritten.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<int>  $mediaIds
     * @param  array<int, string>  $tenantOf
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function oldAddresses(array $mediaIds, array $tenantOf): array
    {
        $mediaModel = config('media-library.media_model') ?: Media::class;
        $rows = [];

        foreach (array_chunk($mediaIds, 500) as $chunk) {
            foreach ($mediaModel::query()->whereKey($chunk)->orderBy('id')->get() as $media) {
                $rows[] = [$tenantOf[(int) $media->getKey()] ?? '', (int) $media->getKey(), $media->getUrl()];
            }
        }

        return $rows;
    }

    /**
     * The media ids `--keep` names; null when it names something else.
     *
     * @return list<int>|null
     */
    private function keep(): ?array
    {
        $ids = array_filter(array_map('trim', explode(',', (string) $this->option('keep'))), fn (string $id): bool => $id !== '');

        foreach ($ids as $id) {
            if (! ctype_digit($id)) {
                return null;
            }
        }

        return array_values(array_map('intval', $ids));
    }

    /**
     * @return iterable<\Illuminate\Database\Eloquent\Model>
     */
    protected function tenants(): iterable
    {
        $query = Cms::tenantModel()::query();

        if (filled($tenant = (string) $this->option('tenant'))) {
            ctype_digit($tenant)
                ? $query->whereKey($tenant)
                : $query->where('site_key', $tenant);
        }

        return $query->lazyById(50);
    }
}
