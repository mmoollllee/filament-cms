<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\PathGenerator;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Reports content rows whose stored `path` is not what {@see PathGenerator} would store —
 * read-only, so it can be run before a deploy rather than after one.
 *
 * A stored path that is not a fixpoint of the generator is the shape every bug in this
 * subsystem has taken: the column answers one URL while resolvedPath() answers another, and
 * which of the two a visitor gets depends on which code path serves them. Such a row is
 * rewritten the next time anything saves it — silently, and with no redirect, because
 * nothing on the save path writes one.
 *
 * The rows worth acting on first are the BLOCKED ones: their corrected path is already held
 * by a different record, so the save that would fix them instead raises a validation error
 * — every time, on a field the editor never touched. Those are the only finding that fails
 * the command, so it can gate a deploy without a merely-informational drift report doing so.
 */
class CheckContentPathsCommand extends Command
{
    protected $signature = 'cms:paths:check
        {--tenant= : Limit the check to a single tenant ID}';

    protected $description = 'Report content rows whose stored path is not the one the generator would store (read-only)';

    public function handle(PathGenerator $generator): int
    {
        $tenants = $this->option('tenant')
            ? Cms::tenantModel()::where('id', $this->option('tenant'))->get()
            : Cms::tenantModel()::all();

        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');

            return self::FAILURE;
        }

        $blocked = 0;
        $drifted = 0;

        $currentTenant = app(CurrentTenant::class);
        $previous = $currentTenant->get();

        try {
            foreach ($tenants as $tenant) {
                // The generator resolves a record's site_key through its tenant relation,
                // and walks the ancestor chain to do it. Announcing the tenant we are
                // checking lets ensureTenantLoaded() answer from memory instead of
                // querying the same row back once per ancestor, per row.
                $currentTenant->set($tenant);

                [$rows, $blockedHere] = $this->driftFor($tenant, $generator);

                $drifted += count($rows);
                $blocked += $blockedHere;

                $this->reportTenant($tenant, $rows);
            }
        } finally {
            $previous === null ? $currentTenant->forget() : $currentTenant->set($previous);
        }

        if ($drifted === 0) {
            $this->info('Every content path is what the generator would store.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("{$drifted} row(s) would be rewritten by their next save.");

        if ($blocked === 0) {
            $this->line('None of them is blocked — each would move cleanly, but without a redirect.');

            return self::SUCCESS;
        }

        $this->error("{$blocked} of them cannot move: another record already holds the corrected path.");
        $this->line('Those rows raise a validation error on every save until one of the two is renamed.');

        return self::FAILURE;
    }

    /**
     * The tenant's drifted rows, plus how many of them are blocked.
     *
     * @return array{0: array<int, array<string, string>>, 1: int}
     */
    protected function driftFor(Tenant $tenant, PathGenerator $generator): array
    {
        /** @var EloquentCollection<int, Content> $contents */
        $contents = Cms::contentModel()::query()
            ->where('tenant_id', $tenant->getKey())
            ->with('parent')
            ->get();

        // The whole tenant is in memory already, so occupancy is answered from it rather
        // than with a query per row.
        $owners = $contents->whereNotNull('path')->keyBy('path');

        $rows = [];
        $blocked = 0;

        foreach ($contents as $content) {
            // generate() writes back onto the model it is handed ($content->slug), so it is
            // asked on a clone — the same precaution resolvedPath() takes.
            $probe = clone $content;
            $probe->setRelation('tenant', $tenant);

            $would = $generator->generate($probe);

            if ($would === $content->getAttribute('path')) {
                continue;
            }

            $owner = $would === null ? null : $owners->get($would);
            $isBlocked = $owner !== null && $owner->getKey() !== $content->getKey();

            if ($isBlocked) {
                $blocked++;
            }

            $rows[] = [
                'id' => (string) $content->getKey(),
                'type' => (string) $content->getAttribute('content_type'),
                'title' => (string) $content->getAttribute('title'),
                'stored' => $content->getAttribute('path') ?? '—',
                'would be' => $would ?? '—',
                'blocked by' => $isBlocked ? sprintf('#%s %s', $owner->getKey(), $owner->getAttribute('title')) : '',
            ];
        }

        return [$rows, $blocked];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    protected function reportTenant(Tenant $tenant, array $rows): void
    {
        $label = sprintf('%s (site_key %s)', $tenant->getAttribute('name') ?? $tenant->getKey(), $tenant->getAttribute('site_key'));

        // A tenant name is user data, and the console formatter reads "<…>" as markup.
        $label = OutputFormatter::escape($label);

        if ($rows === []) {
            $this->line("<info>✓</info> {$label}");

            return;
        }

        $this->newLine();
        $this->line("<comment>{$label}</comment>");
        $this->table(['ID', 'Type', 'Title', 'Stored', 'Would be', 'Blocked by'], $rows);
    }
}
