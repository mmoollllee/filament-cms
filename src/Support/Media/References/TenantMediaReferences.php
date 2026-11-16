<?php

namespace Mmoollllee\Cms\Support\Media\References;

use Illuminate\Support\Collection;
use Mmoollllee\Cms\Cms;

/**
 * Media references in a tenant's own settings: the branding images (logos,
 * favicon, default og:image) and images placed in its rich-text settings
 * (imprint, privacy, footer). Live while the tenant is on the air — they are
 * on every page it serves.
 */
final class TenantMediaReferences extends ScansRecords
{
    /** Branding columns (MediaField in the tenant profile) => what they are. */
    public const BRANDING_COLUMNS = [
        'logo_path' => 'Logo',
        'secondary_logo_path' => 'Zweites Logo',
        'mail_logo_path' => 'E-Mail-Logo',
        'favicon_path' => 'Favicon',
        'default_og_image_path' => 'Standard-Vorschaubild',
    ];

    public static function mediaUsagesForTenants(Collection $tenants): iterable
    {
        $tenantKeys = static::tenantKeys($tenants);

        if ($tenantKeys === []) {
            return [];
        }

        $model = Cms::tenantModel();
        $branding = MediaReferenceScanner::make();
        $usages = [];

        foreach ($model::query()->whereKey($tenantKeys)->toBase()->get() as $row) {
            $columns = (array) $row;
            $details = [];

            foreach (self::BRANDING_COLUMNS as $column => $label) {
                foreach ($branding->scan([$column => $columns[$column] ?? null]) as $id) {
                    $details[$id] ??= $label;
                }
            }

            // Everything else only counts through rich text (data-id images).
            $rest = array_map(static::decode(...), array_diff_key($columns, self::BRANDING_COLUMNS));
            $applied = array_values(array_unique([...array_keys($details), ...$branding->scan($rest)]));

            if ($applied === []) {
                continue;
            }

            array_push($usages, ...static::usages(
                owner: static::hydrate($model, $row),
                label: (string) ($row->name ?? '#'.$row->id),
                appliedIds: $applied,
                draftIds: [],
                live: ! static::isArchived($row->visibility ?? null),
                details: $details,
            ));
        }

        return $usages;
    }
}
