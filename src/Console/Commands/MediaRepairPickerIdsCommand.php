<?php

namespace Mmoollllee\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Filament\RichEditor\MediaLibraryPickerPlugin;
use Mmoollllee\Cms\Observers\ContentCacheObserver;
use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

/**
 * Rewrites Mediathek picker identifiers that only work in one environment.
 *
 * Between v0.17.2 and v0.17.3 the RichEditor picker stored what the upstream
 * plugin hands out: the driver key encrypted with `APP_KEY` and base64-encoded.
 * That is a transport token. Pull the database into another environment — or
 * rotate `APP_KEY` — and it stops decrypting, `MediaUrlResolver` falls through
 * to its legacy-path branch, and every picked image resolves to
 * `/storage/<ciphertext>`: dead in the panel and on the site alike.
 *
 * {@see MediaLibraryPickerPlugin} stores the plain item id now. This repairs
 * what was written before that.
 *
 * Two ways back to the id, because either environment must be able to run this:
 * where the key still decrypts (the one that wrote it) the id comes from the
 * key; everywhere else it is read out of the tag's own `src`, which points at
 * `/storage/{id}/…`. A node that yields neither is reported and left alone.
 *
 * ONE-TIME command with an end date. Once every install reports zero remaining
 * key hashes, this class, its test and its mention in the docs are deleted
 * rather than kept around as something a future reader has to recognise as
 * obsolete. Check every consumer first.
 */
class MediaRepairPickerIdsCommand extends Command
{
    protected $signature = 'cms:media:repair-picker-ids
        {--dry-run : Report what would change without writing}';

    protected $description = 'ONE-TIME repair (delete this command once every install has run it): rewrite environment-bound Mediathek picker keys in rich text to portable media ids';

    /** @var array{scanned: int, repaired: int, rows: int, unresolved: int} */
    protected array $stats = ['scanned' => 0, 'repaired' => 0, 'rows' => 0, 'unresolved' => 0];

    public function handle(): int
    {
        $columns = ['blocks', 'payload', 'meta', 'draft'];

        // Global scopes off and no tenant filter: a repair has to reach every
        // row, including those of a tenant this console run cannot resolve.
        foreach (Cms::contentModel()::query()->withoutGlobalScopes()->cursor() as $content) {
            $this->repairRow($content, $columns);
        }

        if ($fragmentModel = Cms::fragmentModel()) {
            foreach ($fragmentModel::query()->withoutGlobalScopes()->cursor() as $fragment) {
                $this->repairRow($fragment, ['blocks', 'draft']);
            }
        }

        $this->report();

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $columns
     */
    protected function repairRow(Model $row, array $columns): void
    {
        $changed = false;

        foreach ($columns as $column) {
            if (! array_key_exists($column, $row->getAttributes())) {
                continue;
            }

            $value = $row->{$column};

            if (blank($value)) {
                continue;
            }

            $repaired = $this->repairValue($value);

            if ($repaired !== $value) {
                $row->{$column} = $repaired;
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        $this->stats['rows']++;

        if ($this->option('dry-run')) {
            return;
        }

        // Quietly, so a repair does not bump timestamps or stack a content
        // version on every row it touches...
        $row->saveQuietly();

        // ...but the frontend caches rememberForever and this observer is its
        // only invalidation. Skipping it leaves the old markup serving, which
        // reads exactly like the command having done nothing.
        if ($row instanceof Content) {
            app(ContentCacheObserver::class)->contentSaved($row);
        }
    }

    /**
     * Rich text can sit at any depth of a block tree, so walk rather than guess
     * at the shape.
     */
    protected function repairValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->repairValue($item), $value);
        }

        if (! is_string($value) || ! str_contains($value, 'data-id=')) {
            return $value;
        }

        return preg_replace_callback('#<img\b[^>]*>#i', function (array $matches): string {
            return $this->repairTag($matches[0]);
        }, $value) ?? $value;
    }

    protected function repairTag(string $tag): string
    {
        if (preg_match('#\sdata-id="([^"]*)"#i', $tag, $matched) !== 1) {
            return $tag;
        }

        $id = $matched[1];

        // A conversion may be appended as `|name`; it stays as it is, only the
        // key in front of it is environment-bound.
        [$key, $suffix] = str_contains($id, '|')
            ? explode('|', $id, 2)
            : [$id, null];

        if ($key === '' || ctype_digit($key)) {
            return $tag;
        }

        $this->stats['scanned']++;

        $itemId = $this->itemIdFor($key, $tag);

        if ($itemId === null) {
            $this->stats['unresolved']++;
            $this->warn('  Could not resolve: '.$key);

            return $tag;
        }

        $this->stats['repaired']++;

        $replacement = ' data-id="'.$itemId.($suffix === null ? '' : '|'.$suffix).'"';

        return preg_replace('#\sdata-id="[^"]*"#i', $replacement, $tag, limit: 1) ?? $tag;
    }

    /**
     * The item id behind a stored key, from the key itself where it still
     * decrypts, otherwise from the `/storage/{id}/…` URL the tag already
     * carries.
     */
    protected function itemIdFor(string $key, string $tag): ?int
    {
        $fromKey = MediaUrlResolver::normalize($key);

        if (is_int($fromKey)) {
            return $fromKey;
        }

        if (preg_match('#\ssrc="[^"]*?/(\d+)/(?:conversions/)?[^/"]+"#i', $tag, $matched) !== 1) {
            return null;
        }

        $id = (int) $matched[1];

        // Trust the URL only as far as a row that exists — a hand-written src
        // could point anywhere, and writing an id with no item behind it would
        // trade one dead image for another.
        return MediaUrlResolver::item($id) !== null ? $id : null;
    }

    protected function report(): void
    {
        $this->newLine();

        $this->table(
            ['Key hashes', 'Repaired', 'Unresolved', 'Rows'],
            [[$this->stats['scanned'], $this->stats['repaired'], $this->stats['unresolved'], $this->stats['rows']]],
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was written.');

            return;
        }

        if ($this->stats['scanned'] === 0) {
            $this->info('No environment-bound picker keys left. This command can be deleted once every install reports the same.');
        }
    }
}
