<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Forms\MediaField;
use Mmoollllee\Cms\Support\Media\MediaFolders;
use Mmoollllee\Cms\Support\Media\MediaLibrary;

/**
 * One-time (idempotent) migration of legacy file-path references into the
 * media library: every string value inside contents/fragments JSON columns
 * (blocks, payload, meta AND the draft stash) plus the tenants' `*_path`
 * columns that points at an existing file on the public disk becomes a
 * MediaLibraryItem, and the reference is rewritten to the item id.
 *
 * The scan is VALUE-based, not key-based, on purpose: real installs reference
 * media under arbitrary keys (`payload.galerie` arrays, `masszeichnung`,
 * `feature_card.image_path`, WordPress-era `2020/01/…` paths) — a fixed key
 * list would miss most of them. Values starting with `/` or a scheme (inline
 * rich-text URLs, `meta.og_image_url`) are left untouched; the resolver keeps
 * serving them as before.
 *
 * MUST run before editors work in the panel after the media library goes
 * live: a MediaPicker cannot hydrate a legacy path — it would show empty and
 * drop the value on save. The frontend needs no import (legacy fallback).
 *
 * Files are imported with `preservingOriginal()` — originals stay on disk as
 * rollback safety. Idempotency: rewritten values are ints (skipped on rerun);
 * already-imported paths are recognized via the `cms_legacy_path` custom
 * property stored on the Spatie media row.
 */
class MediaImportCommand extends Command
{
    protected $signature = 'cms:media:import
        {--dry-run : Analyse and report only — no writes}
        {--tenant= : Limit to one tenant (site_key)}
        {--all : Also import unreferenced files below the tenant directories}
        {--sync : Generate conversions synchronously (installs without a queue worker)}';

    protected $description = 'Import legacy file-path references (blocks, payload, meta, drafts, tenant branding) into the media library and rewrite them to item ids';

    /** @var array<int, array<string, int>> [tenant id][path] => item id */
    protected array $map = [];

    /** @var array<int, array<string, string>> [tenant id][path] => alt text */
    protected array $altTexts = [];

    /** @var array<string, array<string, int>> [site_key][metric] */
    protected array $stats = [];

    /** @var array<string, array<int, string>> [site_key][] => "path (reason)" */
    protected array $missing = [];

    public function handle(): int
    {
        if (! MediaLibrary::enabled()) {
            $this->error('The media library is not available (ralphjsmit/laravel-filament-media-library not installed, or disabled via Cms::disableMediaLibrary()).');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            config(['media-library.queue_conversions_by_default' => false]);
        }

        $tenants = Cms::tenantModel()::query()
            ->when($this->option('tenant'), fn ($query, $siteKey) => $query->where('site_key', $siteKey))
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('No matching tenants found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $this->importTenant($tenant, $tenants->count() === 1);
        }

        $this->report();

        return self::SUCCESS;
    }

    protected function importTenant(Model $tenant, bool $isOnlyTenant): void
    {
        $this->stats[$tenant->site_key] = ['refs' => 0, 'imported' => 0, 'rows' => 0];

        $this->collectAltTexts($tenant);

        // Content + fragment rows of this tenant — every JSON column that can
        // carry media refs, the draft stash included (a stale draft would
        // otherwise re-apply legacy paths over the rewritten live data).
        // cursor() keeps memory bounded on installs with thousands of rows
        // carrying large JSON columns.
        foreach (Cms::contentModel()::query()->whereBelongsTo($tenant)->cursor() as $content) {
            $this->rewriteRow($content, ['blocks', 'payload', 'meta', 'draft'], $tenant);
        }

        if ($fragmentModel = Cms::fragmentModel()) {
            foreach ($fragmentModel::query()->whereBelongsTo($tenant)->cursor() as $fragment) {
                $this->rewriteRow($fragment, ['blocks', 'draft'], $tenant);
            }
        }

        $this->rewriteTenantColumns($tenant);

        if ($this->option('all')) {
            $this->importUnreferenced($tenant, $isOnlyTenant);
        }
    }

    /**
     * Pre-pass: remember block-level `media_alt` overrides so imported items
     * get their alt text prefilled from the closest existing source.
     */
    protected function collectAltTexts(Model $tenant): void
    {
        $walk = function (mixed $value) use (&$walk, $tenant): void {
            if (! is_array($value)) {
                return;
            }

            $path = $value['media_path'] ?? null;
            $alt = $value['media_alt'] ?? null;

            if (is_string($path) && filled($alt) && is_string($alt)) {
                $this->altTexts[$tenant->getKey()][$path] = $alt;
            }

            foreach ($value as $nested) {
                $walk($nested);
            }
        };

        foreach (Cms::contentModel()::query()->whereBelongsTo($tenant)->cursor() as $content) {
            $walk($content->blocks);
            $walk($content->draft);
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    protected function rewriteRow(Model $row, array $columns, Model $tenant): void
    {
        $changed = false;

        foreach ($columns as $column) {
            $value = $row->{$column};

            if (blank($value)) {
                continue;
            }

            $rewritten = $this->rewriteValue($value, $tenant);

            if ($rewritten !== $value) {
                $row->{$column} = $rewritten;
                $changed = true;
            }
        }

        if ($changed) {
            $this->stats[$tenant->site_key]['rows']++;

            if (! $this->option('dry-run')) {
                $row->saveQuietly();
            }
        }
    }

    protected function rewriteTenantColumns(Model $tenant): void
    {
        $changed = false;

        // Every *_path attribute (apps may add their own beyond the branding
        // set) — the on-disk existence check keeps false positives out.
        foreach ($tenant->getAttributes() as $key => $value) {
            if (! Str::endsWith($key, '_path') || ! is_string($value)) {
                continue;
            }

            if ($this->isImportablePath($value)) {
                $itemId = $this->importFile($value, $tenant);

                if ($itemId !== null) {
                    $tenant->{$key} = (string) $itemId;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->stats[$tenant->site_key]['rows']++;

            if (! $this->option('dry-run')) {
                $tenant->saveQuietly();
            }
        }
    }

    /**
     * Recursively rewrite importable path strings to item ids. Non-string
     * leaves and non-importable strings stay untouched.
     */
    protected function rewriteValue(mixed $value, Model $tenant): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $nested) {
                $result[$key] = $this->rewriteValue($nested, $tenant);
            }

            return $result;
        }

        if (is_string($value) && $this->isImportablePath($value)) {
            $itemId = $this->importFile($value, $tenant);

            return $itemId ?? $value;
        }

        return $value;
    }

    /**
     * Whether a string value is a legacy media reference: a relative path
     * (no scheme, not root-anchored) with a file extension that exists on the
     * public disk. Absolute/rooted URLs stay legacy on purpose — they resolve
     * fine and cannot be tenant-attributed safely.
     */
    protected function isImportablePath(string $value): bool
    {
        if (blank($value) || strlen($value) > 500) {
            return false;
        }

        if (Str::startsWith($value, ['http://', 'https://', '/', 'data:'])) {
            return false;
        }

        if (Str::contains($value, ['<', '>', "\n", '{'])) {
            return false;
        }

        // Media extensions only (MediaField owns the taxonomy): tenants may
        // carry non-media `*_path` columns (sitemap_path, export paths, …)
        // whose files exist on the disk — those must never be rewritten.
        $extension = Str::lower(pathinfo($value, PATHINFO_EXTENSION));

        if (! in_array($extension, MediaField::IMPORTABLE_EXTENSIONS, true)) {
            return false;
        }

        if (Str::startsWith($value, 'livewire-tmp/')) {
            return false;
        }

        return Storage::disk('public')->exists($value);
    }

    /**
     * Import a file once per tenant and return the item id (null in dry-run
     * or when the import fails). Idempotent across runs via the
     * `cms_legacy_path` custom property.
     */
    protected function importFile(string $path, Model $tenant): ?int
    {
        $tenantId = $tenant->getKey();
        $this->stats[$tenant->site_key]['refs']++;

        if (isset($this->map[$tenantId][$path])) {
            return $this->map[$tenantId][$path];
        }

        $itemModel = Cms::mediaItemModel();

        $existing = $itemModel::query()
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenantId)
            ->whereHas('media', fn ($query) => $query->where('custom_properties->cms_legacy_path', $path))
            ->first();

        if ($existing !== null) {
            return $this->map[$tenantId][$path] = (int) $existing->getKey();
        }

        if ($this->option('dry-run')) {
            $this->line("  would import: {$path}", verbosity: 'v');

            return null;
        }

        try {
            $item = $itemModel::query()->create([
                'tenant_type' => $tenant->getMorphClass(),
                'tenant_id' => $tenantId,
                'folder_id' => $this->folderFor($path, $tenant)?->getKey(),
                'alt_text' => $this->altTexts[$tenantId][$path] ?? null,
            ]);

            $item
                ->driver(app(Cms::mediaDriver()))
                ->addMediaFromDisk($path, 'public')
                ->preservingOriginal()
                ->usingName(pathinfo($path, PATHINFO_FILENAME))
                ->withCustomProperties(['cms_legacy_path' => $path])
                ->toMediaCollection($item->getMediaLibraryCollectionName());
        } catch (\Throwable $e) {
            $this->missing[$tenant->site_key][] = "{$path} ({$e->getMessage()})";

            return null;
        }

        $this->stats[$tenant->site_key]['imported']++;

        return $this->map[$tenantId][$path] = (int) $item->getKey();
    }

    /**
     * Target folder: known directory segments (`tenants/{key}/branding/…` or
     * the pre-tenant top-level `branding/…`) map to the default folders,
     * documents go to "Dokumente", everything else to "Seiten".
     */
    protected function folderFor(string $path, Model $tenant): ?Model
    {
        $prefix = "tenants/{$tenant->site_key}/";

        $segment = Str::startsWith($path, $prefix)
            ? Str::before(Str::after($path, $prefix), '/')
            : Str::before($path, '/');

        if (in_array($segment, ['branding', 'seo'], true)) {
            return MediaFolders::ensure(MediaFolders::keyForLegacySegment($segment), $tenant);
        }

        if (in_array(Str::lower(pathinfo($path, PATHINFO_EXTENSION)), MediaField::DOCUMENT_EXTENSIONS, true)) {
            return MediaFolders::ensure(MediaFolders::DOCUMENTS, $tenant);
        }

        return MediaFolders::ensure(MediaFolders::PAGES, $tenant);
    }

    /**
     * --all: sweep files nobody references. Always `tenants/{site_key}/**`;
     * on single-tenant installs additionally the WordPress-era year folders
     * and `branding/` at the disk root (they cannot be tenant-attributed by
     * path, so multi-tenant installs skip them).
     */
    protected function importUnreferenced(Model $tenant, bool $isOnlyTenant): void
    {
        $directories = ["tenants/{$tenant->site_key}"];

        if ($isOnlyTenant) {
            foreach (Storage::disk('public')->directories() as $directory) {
                if (preg_match('/^\d{4}$/', $directory) || $directory === 'branding') {
                    $directories[] = $directory;
                }
            }
        }

        foreach ($directories as $directory) {
            foreach (Storage::disk('public')->allFiles($directory) as $file) {
                if (isset($this->map[$tenant->getKey()][$file]) || ! $this->isImportablePath($file)) {
                    continue;
                }

                $this->importFile($file, $tenant);
            }
        }
    }

    protected function report(): void
    {
        $this->table(
            ['Tenant', 'Referenzen', 'Importiert', 'Zeilen umgeschrieben', 'Fehler'],
            collect($this->stats)->map(fn (array $row, string $siteKey) => [
                $siteKey, $row['refs'], $row['imported'], $row['rows'], count($this->missing[$siteKey] ?? []),
            ]),
        );

        foreach ($this->missing as $siteKey => $lines) {
            foreach ($lines as $line) {
                $this->warn("{$siteKey}: {$line}");
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was written. Re-run without --dry-run to import.');

            return;
        }

        if ($this->map !== []) {
            // Pid suffix + exclusive lock: concurrent runs (full + --tenant=)
            // in the same second must not clobber each other's mapping log.
            $log = storage_path('app/cms-media-import-'.now()->format('Ymd_His').'-'.getmypid().'.json');
            file_put_contents($log, json_encode($this->map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            $this->info("Mapping log: {$log}");
        }
    }
}
