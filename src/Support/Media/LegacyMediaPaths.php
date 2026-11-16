<?php

namespace Mmoollllee\Cms\Support\Media;

use Datlechin\FilamentMenuBuilder\Models\MenuItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\CacheKeys;
use Mmoollllee\Cms\Support\Media\References\MediaReferenceScanner;
use Mmoollllee\Cms\Support\TenantCache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Files stored before the private library existed, still in the guessable
 * `{id}/` — moved once into a directory of their own by `cms:media:secure`
 * ({@see PrivateMediaPaths}).
 *
 * A file a published page shows right now stays where it is: visitors may
 * hold its link — a bookmark, a search result, a mail. Everything else moves,
 * also what only a scheduled page uses: pages store item ids and build the
 * URL on every render. Links stored as text are rewritten to the new
 * directory ({@see rewriteLinks()}).
 */
final class LegacyMediaPaths
{
    /**
     * Move a tenant's old files, all but those a published page shows and
     * those named to keep — a file another site links to, say.
     *
     * @param  list<int>  $keep  media ids to leave where they are
     * @return array{moved: list<int>, kept: int, held: int, failed: int} moved: the media ids that moved — in a dry run, that would; kept: shown by a published page; held: named in `$keep`
     */
    public static function secure(Model $tenant, bool $dryRun = false, array $keep = []): array
    {
        $result = ['moved' => [], 'kept' => 0, 'held' => 0, 'failed' => 0];
        $keep = array_flip($keep);
        $public = [];

        foreach (MediaReferences::scan(collect([$tenant])) as $usage) {
            if ($usage->isPublic()) {
                $public[$usage->itemId] = true;
            }
        }

        $items = Cms::mediaItemModel()::query()
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenant->getKey())
            ->with('media')
            ->lazyById(100);

        foreach ($items as $item) {
            foreach ($item->getMedia($item->getMediaLibraryCollectionName()) as $media) {
                if (PrivateMediaPaths::token($media) !== null) {
                    continue;
                }

                if (isset($public[(int) $item->getKey()])) {
                    $result['kept']++;

                    continue;
                }

                if (isset($keep[(int) $media->getKey()])) {
                    $result['held']++;

                    continue;
                }

                if ($dryRun) {
                    $result['moved'][] = (int) $media->getKey();

                    continue;
                }

                try {
                    // Read again right before moving: an upload or a video
                    // conversion may have replaced the row since its chunk
                    // was loaded.
                    $media = $media->fresh();

                    if ($media !== null && PrivateMediaPaths::moveToPrivateDirectory($media)) {
                        $result['moved'][] = (int) $media->getKey();
                    }
                } catch (Throwable $exception) {
                    $result['failed']++;

                    report($exception);
                }
            }
        }

        // The resolver may already hold the moved files' old paths.
        if ($result['moved'] !== [] && ! $dryRun) {
            MediaUrlResolver::flush();
        }

        return $result;
    }

    /**
     * Point links stored as text at the files' private directories: wherever
     * a string in the CMS's tables — contents and their draft stashes,
     * fragments, tenant settings, menu items, redirects, versions — links
     * into the old `{id}/` of a file that has a directory of its own now.
     * Links in an app's own tables are not touched.
     *
     * Only links to what moved change — the media's file by its exact name,
     * `conversions/`, `responsive-images/`. An old `{id}/` can hold files of
     * an import too, directly or in an `{id}/01/…` tree; they stay where they
     * are, and so do their links. The raw column text is edited, so JSON
     * keeps its exact form, `\/` escapes included. Safe to run again: a link
     * pasted later from an old page is found the next time.
     *
     * @param  list<int>  $pending  media that would move in a dry run, counted as if they had
     * @return int the records rewritten — in a dry run, those that would be
     */
    public static function rewriteLinks(bool $dryRun = false, array $pending = []): int
    {
        $base = MediaReferenceScanner::linkBase();
        $files = self::privateFiles($pending);

        if ($base === null || $files === []) {
            return 0;
        }

        $rewritten = 0;

        foreach (self::linkTables() as $model) {
            $table = fn () => $model->getConnection()->table($model->getTable());
            $key = $model->getKeyName();

            foreach ($table()->lazyById(200, $key) as $row) {
                $changes = [];

                foreach ((array) $row as $column => $value) {
                    if (is_string($value) && ($rewrite = self::rewrite($value, $base, $files)) !== $value) {
                        $changes[$column] = $rewrite;
                    }
                }

                if ($changes === []) {
                    continue;
                }

                $rewritten++;

                if (! $dryRun) {
                    $table()->where($key, $row->{$key})->update($changes);
                }
            }
        }

        if ($rewritten > 0 && ! $dryRun) {
            self::flushCaches();
        }

        return $rewritten;
    }

    /**
     * @param  array<int, array{directory: string, file: string}>  $files  media id => its private directory and file name
     */
    private static function rewrite(string $value, string $base, array $files): string
    {
        foreach (self::spellings($base) as [$before, $slash, $escapes]) {
            if (! str_contains($value, $before)) {
                continue;
            }

            $value = (string) preg_replace_callback(
                '#'.preg_quote($before, '#').'(\d+)'.preg_quote($slash, '#').'#',
                function (array $match) use ($value, $before, $slash, $escapes, $files): string {
                    [$whole, $offset] = $match[0];
                    $file = $files[(int) $match[1][0]] ?? null;

                    return $file !== null && self::linksOwnEntry($value, $offset + strlen($whole), $file['file'], $slash, $escapes)
                        ? $before.$file['directory'].$slash
                        : $whole;
                },
                $value,
                flags: PREG_OFFSET_CAPTURE,
            );
        }

        return $value;
    }

    /**
     * Whether the link going on at `$at` — right after `{id}/` — leads to
     * something that moved: a derivative directory, or the media's file by
     * its exact name, with nothing after it but the link's end (a quote, a
     * query, a closing bracket or full stop of the text around it).
     */
    private static function linksOwnEntry(string $value, int $at, string $fileName, string $slash, int $escapes): bool
    {
        foreach (['conversions', 'responsive-images'] as $directory) {
            if (substr($value, $at, strlen($directory.$slash)) === $directory.$slash) {
                return true;
            }
        }

        foreach (self::fileSpellings($fileName, $escapes) as $spelling) {
            if (substr($value, $at, strlen($spelling)) !== $spelling) {
                continue;
            }

            // What follows up to the next character no file name of the
            // library holds: nothing, or punctuation of the text around it.
            preg_match('#^[^\s"\'<>?\#\\\\&]*#', substr($value, $at + strlen($spelling), 64), $tail);

            if (preg_match('#^[.,:;!)\]]*$#', $tail[0]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A file name the ways a stored link may spell it: as it is,
     * percent-encoded (fully, or the way a browser encodes a URL), and with
     * the JSON escaping of the link's spelling, Unicode escaped or not.
     *
     * @return list<string>
     */
    private static function fileSpellings(string $fileName, int $escapes): array
    {
        $spellings = array_values(array_unique([$fileName, rawurlencode($fileName), self::encodeUri($fileName)]));

        for ($i = 0; $i < max($escapes, 1); $i++) {
            foreach ($spellings as $spelling) {
                $spellings[] = self::jsonEscaped($spelling);
                $spellings[] = self::jsonEscaped($spelling, JSON_UNESCAPED_UNICODE);
            }

            $spellings = array_values(array_unique($spellings));
        }

        return $spellings;
    }

    /** What `encodeURI()` leaves of a file name: reserved characters stay. */
    private static function encodeUri(string $text): string
    {
        return strtr(rawurlencode($text), [
            '%21' => '!', '%23' => '#', '%24' => '$', '%26' => '&', '%27' => "'", '%28' => '(', '%29' => ')',
            '%2A' => '*', '%2B' => '+', '%2C' => ',', '%2F' => '/', '%3A' => ':', '%3B' => ';', '%3D' => '=',
            '%3F' => '?', '%40' => '@',
        ]);
    }

    /**
     * The link base and its slash as written; as JSON stores them
     * (`\/storage\/`); and as JSON inside JSON stores them — a version keeps a
     * column's raw text — in PHP's spelling (`\\\/`) and in MySQL's, whose
     * JSON columns hand slashes back unescaped (`\\/`). With how many JSON
     * escapes the link sits there.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function spellings(string $base): array
    {
        $json = self::jsonEscaped($base);

        return [
            [$base, '/', 0],
            [$json, self::jsonEscaped('/'), 1],
            [str_replace('/', '\\\\/', $base), '\\\\/', 2],
            [self::jsonEscaped($json), self::jsonEscaped(self::jsonEscaped('/')), 2],
        ];
    }

    private static function jsonEscaped(string $text, int $flags = 0): string
    {
        return substr((string) json_encode($text, $flags), 1, -1);
    }

    /**
     * Every library file with a directory of its own, with its file name —
     * and, in a dry run, those that would get one, under a stand-in name.
     *
     * @param  list<int>  $pending
     * @return array<int, array{directory: string, file: string}>
     */
    private static function privateFiles(array $pending): array
    {
        $mediaModel = config('media-library.media_model') ?: Media::class;
        $pending = array_flip($pending);
        $files = [];

        foreach ($mediaModel::query()->where('model_type', MediaLibrary::ITEM_MORPH_ALIAS)->lazyById(500) as $media) {
            $token = PrivateMediaPaths::token($media) ?? (isset($pending[(int) $media->getKey()]) ? str_repeat('x', PrivateMediaPaths::TOKEN_LENGTH) : null);

            if ($token !== null) {
                $files[(int) $media->getKey()] = ['directory' => $media->getKey().'-'.$token, 'file' => (string) $media->file_name];
            }
        }

        return $files;
    }

    /**
     * The CMS's tables that keep links as text.
     *
     * @return list<Model>
     */
    private static function linkTables(): array
    {
        $tables = [];

        foreach ([Cms::contentModel(), Cms::fragmentModel(), Cms::tenantModel(), MenuItem::class, Redirect::class, config('versionable.version_model')] as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $model = new $class;

            if ($model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
                $tables[] = $model;
            }
        }

        return $tables;
    }

    /** Pages, menus and redirects are cached per tenant, a tenant's settings per domain. */
    private static function flushCaches(): void
    {
        foreach (Cms::tenantModel()::query()->lazyById(100) as $tenant) {
            if (filled($domain = $tenant->getAttribute('primary_domain'))) {
                Cache::forget(CacheKeys::tenantDomain((string) $domain));
            }

            app(TenantCache::class)->flush($tenant->getKey());
        }

        MediaReferences::flush();
    }
}
