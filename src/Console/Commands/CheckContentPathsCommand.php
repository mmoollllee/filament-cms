<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\ContentTree;
use Mmoollllee\Cms\Support\Content\PathGenerator;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Reports content rows whose stored `path` is not what {@see PathGenerator} would store — and,
 * with --fix, moves them there.
 *
 * The stored path is the address: routing, links, the sitemap and the breadcrumbs all read
 * it, and nothing recomputes it on the way out. A row drifts when something changes underneath
 * it without saving it — a blueprint gaining or losing a urlPathPrefix, a tenant switching its
 * site_key, an import or a migration writing past the model. Such a row stays reachable where
 * it is stored until it is saved; its next save, from wherever it comes, moves it.
 *
 * Without options the command is read-only, so it can run before a deploy rather than after
 * one. --fix re-saves every drifted row through the model, so each one moves the way an edit
 * would move it: a redirect from the old address, its subtree carried along, its tenant's
 * caches rebuilt. Run it after a deploy that changed a blueprint's addressing.
 *
 * The rows worth acting on first are the BLOCKED ones: their corrected path is already held
 * by a different record, so the save that would fix them instead raises a validation error
 * — every time, on a field the editor never touched. Those are the only finding that fails
 * the command, so it can gate a deploy without a merely-informational drift report doing so;
 * --fix leaves them where they are.
 */
class CheckContentPathsCommand extends Command
{
    protected $signature = 'cms:paths:check
        {--tenant= : Limit the check to a single tenant ID}
        {--fix : Re-save every drifted row that is not blocked, so it moves to the generated path (with a redirect from the old one)}';

    protected $description = 'Report content rows whose stored path is not the one the generator would store (read-only unless --fix)';

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
        $fixed = 0;
        $failed = 0;

        $currentTenant = app(CurrentTenant::class);
        $previous = $currentTenant->get();

        try {
            foreach ($tenants as $tenant) {
                // The generator resolves a record's site_key through its tenant relation.
                // Announcing the tenant we are checking lets ensureTenantLoaded() answer from
                // memory instead of querying the same row back per row.
                $currentTenant->set($tenant);

                [$rows, $blockedHere] = $this->driftFor($tenant, $generator);

                $drifted += count($rows);
                $blocked += $blockedHere;

                $this->reportTenant($tenant, $rows);

                if ($this->option('fix') && $rows !== []) {
                    [$fixedHere, $failedHere] = $this->fix($tenant, $rows, $generator);

                    $fixed += $fixedHere;
                    $failed += $failedHere;
                }
            }
        } finally {
            $previous === null ? $currentTenant->forget() : $currentTenant->set($previous);
        }

        if ($drifted === 0) {
            $this->info('Every content path is what the generator would store.');

            return self::SUCCESS;
        }

        $this->newLine();

        if ($this->option('fix')) {
            $this->info("{$fixed} row(s) moved to their generated path, each with a redirect from the old one.");

            if ($blocked > 0 || $failed > 0) {
                $this->error(($blocked + $failed).' row(s) could not move: another record already holds the path, or the save was refused.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->warn("{$drifted} row(s) would be rewritten by their next save.");

        if ($blocked === 0) {
            $this->line('None of them is blocked — `cms:paths:check --fix` moves them, each with a redirect.');

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
            $would = $this->wouldStore($content, $tenant, $generator);

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
     * Re-save the tenant's drifted, unblocked rows — parents before their descendants, so a
     * subtree moves once, carried along by its top-most drifted row, rather than row by row.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{0: int, 1: int} moved rows, refused saves
     */
    protected function fix(Tenant $tenant, array $rows, PathGenerator $generator): array
    {
        $tree = app(ContentTree::class);

        $candidates = Cms::contentModel()::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereKey(array_column(array_filter($rows, fn (array $row): bool => $row['blocked by'] === ''), 'id'))
            ->get()
            ->sortBy(fn (Content $content): int => count($tree->ancestorsOf($content)));

        $fixed = 0;
        $failed = 0;

        foreach ($candidates as $candidate) {
            // A parent moved earlier in this loop has carried this row along already.
            $content = $candidate->fresh();

            if ($content === null || $this->wouldStore($content, $tenant, $generator) === $content->getAttribute('path')) {
                continue;
            }

            try {
                // The saving hook regenerates the path; the saved hook leaves the redirect.
                $content->save();
                $fixed++;
            } catch (ValidationException $exception) {
                $failed++;
                $this->warn(sprintf('  #%s %s: %s', $content->getKey(), OutputFormatter::escape((string) $content->getAttribute('title')), implode(' ', $exception->validator->errors()->all())));
            }
        }

        return [$fixed, $failed];
    }

    /**
     * What a save of $content would store. generate() writes back onto the model it is
     * handed ($content->slug), so it is asked on a clone — the same precaution
     * generatedPath() takes.
     */
    protected function wouldStore(Content $content, Tenant $tenant, PathGenerator $generator): ?string
    {
        $probe = clone $content;
        $probe->setRelation('tenant', $tenant);

        return $generator->generate($probe);
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
