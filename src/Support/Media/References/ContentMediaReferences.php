<?php

namespace Mmoollllee\Cms\Support\Media\References;

use BackedEnum;
use Illuminate\Support\Collection;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Enums\ContentVisibility;

/**
 * Media references in contents: blocks, page header (`payload`), SEO (`meta`)
 * as visitors get them, plus the unapplied draft stash.
 *
 * A content is live while visitors can open it — public visibility, inside its
 * publishing window, on a tenant guests may enter (not archived, not open to
 * members only); scheduled while that window has yet to open.
 */
final class ContentMediaReferences extends ScansRecords
{
    /** The columns that make up what visitors get; `draft` is read separately. */
    public const APPLIED_COLUMNS = ['blocks', 'payload', 'meta'];

    public static function mediaUsagesForTenants(Collection $tenants): iterable
    {
        $tenantKeys = static::tenantKeys($tenants);

        if ($tenantKeys === []) {
            return [];
        }

        $model = Cms::contentModel();
        $closed = static::closedTenantKeys($tenants);

        $usages = [];

        foreach (static::referencingRows($model, $tenantKeys, self::APPLIED_COLUMNS) as [$row, $applied, $hidden, $drafted]) {
            $owner = static::hydrate($model, $row, without: [...self::APPLIED_COLUMNS, 'draft', 'references']);
            $visibility = $owner->getAttribute('visibility');
            $visibility = $visibility instanceof BackedEnum ? $visibility->value : ($visibility ?? ContentVisibility::Public->value);

            $reachable = $visibility === ContentVisibility::Public->value && ! isset($closed[$row->tenant_id]);
            $status = ContentStatus::forWindow($owner->getAttribute('publish_from'), $owner->getAttribute('publish_until'));

            array_push($usages, ...static::usages(
                owner: $owner,
                label: filled($row->title ?? null) ? (string) $row->title : '#'.$row->id,
                appliedIds: $applied,
                draftIds: $drafted,
                live: $reachable && $status === ContentStatus::Published,
                scheduled: $reachable && $status === ContentStatus::Scheduled,
                hiddenIds: $hidden,
            ));
        }

        return $usages;
    }
}
