<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Support\Preview\PreviewMode;

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
        return ContentStatus::forWindow($this->publish_from, $this->publish_until);
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
     * The tenant's content as the given user may see it on the FRONTEND.
     *
     * Superadmins and tenant members see everything (unpublished, scheduled,
     * expired) — but ONLY while the "Vorschau" is active, the same gate
     * {@see HasDraft} uses for stash overlays. Without an active preview the
     * frontend renders the live site (published + public) for everyone, so
     * leaving preview mode hides unpublished content again. The panel never
     * calls visibleTo() (it scopes with whereBelongsTo()), so this preview gate
     * never hides anything from editors there.
     *
     * The `visibility = public` filter guards LEGACY rows: the "Nur Eingeloggt"
     * option was removed from the UI (no code path ever honored it for merely
     * logged-in visitors — it behaved exactly like "unveröffentlicht"), but
     * existing `members` rows must keep their only real semantics: hidden
     * outside the preview.
     */
    public function scopeVisibleTo(Builder $query, Tenant $tenant, ?User $user = null): Builder
    {
        $query->whereBelongsTo($tenant);

        // active() is the cheap check — short-circuit before the membership query.
        if (app(PreviewMode::class)->active() && ($user?->isSuperadmin() || $tenant->hasUser($user))) {
            return $query;
        }

        return $query
            ->where('visibility', ContentVisibility::Public->value)
            ->published();
    }
}
