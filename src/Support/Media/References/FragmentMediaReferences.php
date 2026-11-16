<?php

namespace Mmoollllee\Cms\Support\Media\References;

use Illuminate\Support\Collection;
use Mmoollllee\Cms\Cms;

/**
 * Media references in fragments — the reusable block sets pages embed.
 * A fragment has no publishing window of its own: its applied blocks count
 * as live wherever guests may enter the tenant, its draft stash does not.
 */
final class FragmentMediaReferences extends ScansRecords
{
    public static function mediaUsagesForTenants(Collection $tenants): iterable
    {
        $model = Cms::fragmentModel();
        $tenantKeys = static::tenantKeys($tenants);

        if ($model === null || $tenantKeys === []) {
            return [];
        }

        $closed = static::closedTenantKeys($tenants);

        $usages = [];

        foreach (static::referencingRows($model, $tenantKeys, ['blocks']) as [$row, $applied, $hidden, $drafted]) {
            array_push($usages, ...static::usages(
                owner: static::hydrate($model, $row, without: ['blocks', 'draft']),
                label: (string) ($row->title ?? $row->slug ?? '#'.$row->id),
                appliedIds: $applied,
                draftIds: $drafted,
                live: ! isset($closed[$row->tenant_id]),
                hiddenIds: $hidden,
            ));
        }

        return $usages;
    }
}
