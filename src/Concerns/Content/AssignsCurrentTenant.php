<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Assigns the request's resolved tenant ({@see CurrentTenant}) to new records
 * that were created without an explicit tenant_id — content created in the
 * panel always lands on the tenant whose host is being edited.
 */
trait AssignsCurrentTenant
{
    public static function bootAssignsCurrentTenant(): void
    {
        static::creating(function ($content): void {
            if ($content->tenant_id !== null) {
                return;
            }

            $tenant = app(CurrentTenant::class)->get();

            if ($tenant !== null) {
                $content->tenant()->associate($tenant);
            }
        });
    }
}
