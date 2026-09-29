<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Mmoollllee\Cms\Models\NotFoundLog;
use Mmoollllee\Cms\Support\Routing\NotFoundIgnoreList;

/**
 * Prunes stale, low-traffic 404 log rows so the collector cannot grow unbounded under bot
 * scanning. Keeps rows that are either recent or frequently hit (likely a real broken link worth
 * a redirect). Rows whose path the ignore list now matches are deleted regardless of age or hits:
 * they were logged before a rule covered them and would never be recorded today. Scheduled daily
 * by CmsServiceProvider.
 */
class PruneNotFoundLogsCommand extends Command
{
    protected $signature = 'cms:prune-not-found-logs
        {--days= : Delete logs last seen more than this many days ago (default: config)}
        {--min-hits= : Keep logs with at least this many hits regardless of age (default: config)}';

    protected $description = 'Delete stale, low-traffic and ignored 404 log entries';

    public function handle(NotFoundIgnoreList $ignoreList): int
    {
        $days = (int) ($this->option('days') ?? config('cms.redirects.prune_after_days', 90));
        $minHits = (int) ($this->option('min-hits') ?? config('cms.redirects.prune_min_hits', 3));

        $deleted = NotFoundLog::query()
            ->where('hits', '<', $minHits)
            ->where('last_seen_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} stale 404 log entr(ies).");

        $ignored = $this->pruneIgnored($ignoreList);

        $this->info("Pruned {$ignored} ignored 404 log entr(ies).");

        return self::SUCCESS;
    }

    /**
     * The ignore rules are wildcards evaluated in PHP, so the paths are scanned in chunks rather
     * than translated into SQL.
     */
    protected function pruneIgnored(NotFoundIgnoreList $ignoreList): int
    {
        $ids = NotFoundLog::query()
            ->select(['id', 'tenant_id', 'path', 'last_referer'])
            ->with('tenant')
            ->lazyById()
            ->filter(fn (NotFoundLog $log): bool => $ignoreList->ignores(
                $log->path,
                $log->last_referer,
                $log->tenant?->primary_domain,
            ))
            ->pluck('id');

        $deleted = 0;

        foreach ($ids->chunk(500) as $chunk) {
            $deleted += NotFoundLog::query()->whereKey($chunk->all())->delete();
        }

        return $deleted;
    }
}
