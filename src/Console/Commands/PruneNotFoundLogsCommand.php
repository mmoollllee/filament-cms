<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Mmoollllee\Cms\Models\NotFoundLog;

/**
 * Prunes stale, low-traffic 404 log rows so the collector cannot grow unbounded under bot
 * scanning. Keeps rows that are either recent or frequently hit (likely a real broken link worth
 * a redirect). Scheduled daily by CmsServiceProvider.
 */
class PruneNotFoundLogsCommand extends Command
{
    protected $signature = 'cms:prune-not-found-logs
        {--days= : Delete logs last seen more than this many days ago (default: config)}
        {--min-hits= : Keep logs with at least this many hits regardless of age (default: config)}';

    protected $description = 'Delete stale, low-traffic 404 log entries';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('cms.redirects.prune_after_days', 90));
        $minHits = (int) ($this->option('min-hits') ?? config('cms.redirects.prune_min_hits', 3));

        $deleted = NotFoundLog::query()
            ->where('hits', '<', $minHits)
            ->where('last_seen_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} stale 404 log entr(ies).");

        return self::SUCCESS;
    }
}
