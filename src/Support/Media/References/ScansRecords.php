<?php

namespace Mmoollllee\Cms\Support\Media\References;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Mmoollllee\Cms\Contracts\MediaReferenceSource;

/**
 * Shared plumbing for sources that read their references off database rows.
 *
 * Rows are read through the base query builder, never as models: a content
 * or fragment retrieved while the panel's preview is active gets its draft
 * stash laid over the live columns ({@see \Mmoollllee\Cms\Concerns\HasDraft}),
 * which would report draft references as applied ones. The owner each usage
 * points at is hydrated from the raw row without events for the same reason.
 */
abstract class ScansRecords implements MediaReferenceSource
{
    /**
     * The referenced item ids alone — the shape reference-tracking code
     * elsewhere asks for, so it can use the CMS's sources as they are.
     *
     * @param  Collection<int, Model>  $tenants
     * @return array<int, int>
     */
    public static function referencedMediaItemIdsForTenants(Collection $tenants): array
    {
        $ids = [];

        foreach (static::mediaUsagesForTenants($tenants) as $usage) {
            $ids[$usage->itemId] = true;
        }

        return array_keys($ids);
    }

    /**
     * One usage per referenced item, merging where the record holds it.
     *
     * @param  array<int, int>  $appliedIds
     * @param  array<int, int>  $draftIds
     * @param  array<int, string>  $details  item id => what the reference is on the record
     * @param  array<int, int>  $hiddenIds  applied, but only in blocks switched off
     * @return list<MediaUsage>
     */
    protected static function usages(
        Model $owner,
        string $label,
        array $appliedIds,
        array $draftIds,
        bool $live,
        bool $scheduled = false,
        array $details = [],
        array $hiddenIds = [],
    ): array {
        $applied = array_fill_keys($appliedIds, true);
        $hidden = array_diff_key(array_fill_keys($hiddenIds, true), $applied);
        $drafted = array_fill_keys($draftIds, true);
        $usages = [];

        foreach (array_keys($applied + $hidden + $drafted) as $itemId) {
            $usages[] = new MediaUsage(
                itemId: $itemId,
                owner: $owner,
                label: $label,
                applied: isset($applied[$itemId]) || isset($hidden[$itemId]),
                drafted: isset($drafted[$itemId]),
                live: $live,
                scheduled: $scheduled,
                detail: $details[$itemId] ?? null,
                hidden: isset($hidden[$itemId]),
            );
        }

        return $usages;
    }

    /**
     * The rows of the given tenants that reference something, as stored, 200
     * at a time — with what their applied columns show, what they hold only
     * in switched-off blocks, and what their draft stash holds.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, int|string>  $tenantKeys
     * @param  list<string>  $appliedColumns
     * @return iterable<array{0: object, 1: array<int, int>, 2: array<int, int>, 3: array<int, int>}>
     */
    protected static function referencingRows(string $model, array $tenantKeys, array $appliedColumns): iterable
    {
        $scanner = MediaReferenceScanner::make();

        foreach ($model::query()->whereIn('tenant_id', $tenantKeys)->toBase()->lazyById(200) as $row) {
            $applied = $scanner->scan(array_map(fn (string $column) => static::decode($row->{$column} ?? null), $appliedColumns));
            $hidden = $scanner->hidden();
            $drafted = [...$scanner->scan(static::decode($row->draft ?? null)), ...$scanner->hidden()];

            if ($applied !== [] || $hidden !== [] || $drafted !== []) {
                yield [$row, $applied, $hidden, $drafted];
            }
        }
    }

    /**
     * The owner a usage points at, from the raw row and without the columns
     * named: the stored content the references were found in would otherwise
     * stay in memory with every usage.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $without
     */
    protected static function hydrate(string $model, object $row, array $without = []): Model
    {
        $instance = (new $model)->newInstance([], true);
        $instance->setRawAttributes(array_diff_key((array) $row, array_flip($without)), true);

        return $instance;
    }

    /** A JSON column as stored: decoded when it is JSON, as-is otherwise. */
    public static function decode(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $first = $value[0];

        if ($first !== '{' && $first !== '[') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * Tenant keys grouped for a `whereIn`, so a scan over several tenants is
     * one query per source.
     *
     * @param  Collection<int, Model>  $tenants
     * @return array<int, int|string>
     */
    protected static function tenantKeys(Collection $tenants): array
    {
        return $tenants->map(fn (Model $tenant) => $tenant->getKey())->filter()->values()->all();
    }

    /** Whether a tenant row has been taken off the air. */
    protected static function isArchived(mixed $visibility): bool
    {
        $value = $visibility instanceof \BackedEnum ? $visibility->value : $visibility;

        return $value === \Mmoollllee\Cms\Enums\TenantVisibility::Archived->value;
    }

    /**
     * Whether guests are kept out of a tenant's pages — archived, or open to
     * its members only (a site still being finished): what its pages show is
     * not public, whatever their own status says.
     */
    protected static function isClosedToGuests(mixed $visibility): bool
    {
        $value = $visibility instanceof \BackedEnum ? $visibility->value : $visibility;

        return $value !== null && $value !== \Mmoollllee\Cms\Enums\TenantVisibility::Public->value;
    }

    /**
     * The keys of the tenants whose pages guests cannot open.
     *
     * @param  Collection<int, Model>  $tenants
     * @return array<int|string, true>
     */
    protected static function closedTenantKeys(Collection $tenants): array
    {
        return $tenants
            ->filter(fn (Model $tenant): bool => static::isClosedToGuests($tenant->getAttribute('visibility')))
            ->mapWithKeys(fn (Model $tenant): array => [$tenant->getKey() => true])
            ->all();
    }
}
