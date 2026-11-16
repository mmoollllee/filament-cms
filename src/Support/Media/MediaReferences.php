<?php

namespace Mmoollllee\Cms\Support\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\MediaReferenceSource;
use Mmoollllee\Cms\Support\Media\References\MediaReferenceScanner;
use Mmoollllee\Cms\Support\Media\References\MediaUsage;
use Mmoollllee\Cms\Support\Media\References\ScansRecords;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

/**
 * Where media-library items are used, aggregated over every registered
 * {@see MediaReferenceSource}.
 *
 * Always answered from the stored content, never from an index of its own:
 * an index has to be kept in step with every write, and the draft stash is
 * written straight through the query builder, with no model event to hook.
 * A scan reads the truth. It is memoized per request and tenant — the usage
 * list and the delete guard ask the same question several times in one
 * request — and flushed with the other request-scoped media caches.
 *
 * The Mediathek's file info is the one exception: it renders on every click
 * in the library, so it reads through a cache that lives across requests
 * ({@see shown()}). The delete guard never does.
 */
final class MediaReferences
{
    /** How long the file info may lag behind a write the CMS does not see — one of an app's own sources. */
    private const SHOWN_SECONDS = 300;

    private const VERSION_KEY = 'cms:media-usages:version';

    /** @var array<string, Collection<int, MediaUsage>> */
    private static array $memo = [];

    /** @var array<string, array<int, Collection<int, MediaUsage>>> tenant => item id => its uses */
    private static array $byItem = [];

    /** @var array<string, Model|false> `tenant_type:tenant_id` => the tenant, false when it is gone */
    private static array $tenants = [];

    /**
     * Every reference in a tenant's records, draft stashes included.
     *
     * @return Collection<int, MediaUsage>
     */
    public static function forTenant(Model $tenant): Collection
    {
        return self::$memo[self::key($tenant)] ??= self::scan(collect([$tenant]));
    }

    /**
     * @param  Collection<int, Model>  $tenants
     * @return Collection<int, MediaUsage>
     */
    public static function scan(Collection $tenants): Collection
    {
        $usages = [];

        foreach (Cms::mediaReferenceSources() as $source) {
            foreach ($source::mediaUsagesForTenants($tenants) as $usage) {
                $usages[] = $usage;
            }
        }

        return collect($usages);
    }

    /**
     * The uses of one item, looked up in the item's own tenant (references
     * never cross tenants; a satellite shows its parent's branding through
     * inheritance, not through a stored reference).
     *
     * @return Collection<int, MediaUsage>
     */
    public static function of(MediaLibraryItem $item): Collection
    {
        $tenant = self::tenantOf($item);

        if ($tenant === null) {
            return collect();
        }

        // Indexed once per tenant: a bulk delete asks for every selected item.
        $byItem = self::$byItem[self::key($tenant)] ??= self::forTenant($tenant)
            ->groupBy(fn (MediaUsage $usage): int => $usage->itemId)
            ->all();

        return ($byItem[(int) $item->getKey()] ?? collect())->values();
    }

    /**
     * The uses of one item for the Mediathek's file info. Read across
     * requests from a cache that every write through the CMS renews
     * ({@see renew()}); a write only an app's own source sees shows after at
     * most five minutes.
     *
     * @return Collection<int, MediaUsage>
     */
    public static function shown(MediaLibraryItem $item): Collection
    {
        $tenant = self::tenantOf($item);

        if ($tenant === null) {
            return collect();
        }

        // Plain values: an app's cache may refuse to unserialize objects.
        $byItem = Cache::remember(
            'cms:media-usages:'.self::version().':'.self::key($tenant),
            self::SHOWN_SECONDS,
            fn (): array => self::forTenant($tenant)
                ->groupBy(fn (MediaUsage $usage): int => $usage->itemId)
                ->map(fn (Collection $usages): array => $usages->map(fn (MediaUsage $usage): array => $usage->toArray())->values()->all())
                ->all(),
        );

        return collect($byItem[(int) $item->getKey()] ?? [])->map(fn (array $usage): MediaUsage => MediaUsage::fromArray($usage));
    }

    /**
     * The items among the given ones that something still uses, with their
     * uses — what blocks a delete.
     *
     * @param  iterable<MediaLibraryItem>  $items
     * @return array<int, array{item: MediaLibraryItem, usages: Collection<int, MediaUsage>}>
     */
    public static function inUse(iterable $items): array
    {
        $blocked = [];

        foreach ($items as $item) {
            $usages = self::of($item);

            if ($usages->isNotEmpty()) {
                $blocked[(int) $item->getKey()] = ['item' => $item, 'usages' => $usages];
            }
        }

        return $blocked;
    }

    /**
     * The items a record's stored content refers to that no longer exist —
     * deleted after that content was written, as a restored revision brings
     * back: revisions do not keep a file from being deleted, or with dozens
     * kept per page hardly a file could go. Read from the row as stored.
     *
     * @param  list<string>  $columns
     * @return list<int>
     */
    public static function missingIn(Model $record, array $columns): array
    {
        $row = $record->getConnection()->table($record->getTable())->where($record->getKeyName(), $record->getKey())->first();

        if ($row === null) {
            return [];
        }

        $scanner = MediaReferenceScanner::make();
        $ids = $scanner->scan(array_map(fn (string $column): mixed => ScansRecords::decode($row->{$column} ?? null), $columns));
        $ids = array_values(array_unique([...$ids, ...$scanner->hidden()]));

        if ($ids === []) {
            return [];
        }

        $model = Cms::mediaItemModel();
        $existing = $model::query()
            ->withoutGlobalScopes()
            ->whereKey($ids)
            ->pluck((new $model)->getKeyName())
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_diff($ids, $existing));
    }

    public static function tenantOf(MediaLibraryItem $item): ?Model
    {
        $type = $item->getAttribute('tenant_type');
        $id = $item->getAttribute('tenant_id');

        if (blank($type) || blank($id)) {
            return null;
        }

        if ($item->relationLoaded('tenant')) {
            $tenant = $item->getRelation('tenant');

            return $tenant instanceof Model ? $tenant : null;
        }

        // Once per tenant and request: the items of one library share it.
        $tenant = self::$tenants[$type.':'.$id] ??= ($item->tenant()->first() ?? false);

        return $tenant instanceof Model ? $tenant : null;
    }

    /**
     * After a write that can change where files are used: this request asks
     * afresh, and so does every file info from now on.
     */
    public static function renew(): void
    {
        self::flush();

        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    /** Forget the scans (request teardown, after a write that changes the answer, tests). */
    public static function flush(): void
    {
        self::$memo = [];
        self::$byItem = [];
        self::$tenants = [];
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    private static function key(Model $tenant): string
    {
        return $tenant->getMorphClass().':'.$tenant->getKey();
    }
}
