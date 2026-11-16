<?php

namespace Mmoollllee\Cms\Support\Media\References;

use Illuminate\Support\Collection;
use Mmoollllee\Cms\Models\Redirect;

/**
 * Files a redirect leads to — `/preisliste` to the PDF. Shown while the
 * redirect is active on a tenant guests may enter.
 */
final class RedirectMediaReferences extends ScansRecords
{
    public static function mediaUsagesForTenants(Collection $tenants): iterable
    {
        $tenantKeys = static::tenantKeys($tenants);

        if ($tenantKeys === []) {
            return [];
        }

        $scanner = MediaReferenceScanner::make();
        $closed = static::closedTenantKeys($tenants);
        $usages = [];

        foreach (Redirect::query()->whereIn('tenant_id', $tenantKeys)->whereNotNull('to_url')->toBase()->get() as $row) {
            $applied = $scanner->scan((string) $row->to_url);

            if ($applied === []) {
                continue;
            }

            array_push($usages, ...static::usages(
                owner: static::hydrate(Redirect::class, $row),
                label: (string) $row->from_path,
                appliedIds: $applied,
                draftIds: [],
                live: (bool) $row->is_active && ! isset($closed[$row->tenant_id]),
            ));
        }

        return $usages;
    }
}
