<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Assigns the request's resolved tenant ({@see CurrentTenant}) to new records
 * that were created without an explicit tenant_id — content created in the
 * panel always lands on the tenant whose host is being edited.
 *
 * This hooks `saving`, not `creating`: Eloquent fires `saving` FIRST, and
 * {@see GeneratesPathAndSlug} needs the tenant there to look up the blueprint by
 * site_key. Assigned on `creating`, the tenant arrived one hook too late — the
 * blueprint lookup came back null, PathGenerator bailed out, and a record
 * created in the panel was stored with the raw form path instead of the
 * parent-driven one (a wrong URL, and a permanent unique-index collision when a
 * sibling already owned the correct path).
 *
 * The move sets `tenant_id` itself, so the record is consistent before the path is
 * generated; what makes the fix independent of the order the two `saving` listeners
 * happen to boot in is {@see GeneratesPathAndSlug::ensureTenantLoaded()}, which falls
 * back to the request's tenant on its own. Do not "restore" the ordering assumption.
 */
trait AssignsCurrentTenant
{
    public static function bootAssignsCurrentTenant(): void
    {
        static::saving(function (Model $content): void {
            if ($content->exists || $content->tenant_id !== null) {
                return;
            }

            $tenant = app(CurrentTenant::class)->get();

            if ($tenant !== null) {
                $content->tenant()->associate($tenant);
            }
        });
    }
}
