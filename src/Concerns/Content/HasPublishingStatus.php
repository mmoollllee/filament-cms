<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Enums\ContentVisibility;

/**
 * Publishing window + visibility for Content models.
 *
 * The engine's contract with the Content model beyond Contracts\Content: the
 * resolver, sitemap and 404-suggestions all query `visibleTo()` / `ofType()`,
 * the resource table reads `resolved_status`. Shipped as a trait so the logic
 * updates through the package instead of living as per-app copies.
 *
 * Expects the columns `publish_from`/`publish_until` (datetime casts),
 * `visibility` (ContentVisibility cast), `content_type` and a `tenant` relation.
 */
trait HasPublishingStatus
{
    public function status(): ContentStatus
    {
        if ($this->publish_from === null) {
            return ContentStatus::Draft;
        }

        if ($this->publish_from->isFuture()) {
            return ContentStatus::Scheduled;
        }

        if ($this->publish_until !== null && $this->publish_until->isPast()) {
            return ContentStatus::Expired;
        }

        return ContentStatus::Published;
    }

    public function isPublished(): bool
    {
        return $this->status() === ContentStatus::Published;
    }

    public function getResolvedStatusAttribute(): string
    {
        return $this->status()->value;
    }

    /**
     * @param  string|array<int, string>  $types
     */
    public function scopeOfType(Builder $query, string|array $types): Builder
    {
        return $query->whereIn('content_type', (array) $types);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->whereNotNull('publish_from')
            ->where('publish_from', '<=', now())
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('publish_until')
                    ->orWhere('publish_until', '>', now());
            });
    }

    /**
     * The tenant's content as the given user may see it: everything for
     * superadmins and tenant members, published public content for guests.
     */
    public function scopeVisibleTo(Builder $query, Tenant $tenant, ?User $user = null): Builder
    {
        $query->whereBelongsTo($tenant);

        if ($user?->isSuperadmin() || $tenant->hasUser($user)) {
            return $query;
        }

        return $query
            ->where('visibility', ContentVisibility::Public->value)
            ->published();
    }
}
