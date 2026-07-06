<?php

namespace Mmoollllee\Cms\Concerns\Tenant;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\User;

/**
 * The tenant's content relation plus the visibility-filtered accessor the
 * frontend (listings, onepager shells, sitemaps) reads.
 *
 * Override visibleContents() in the model for project-specific filtering
 * (e.g. hiding jobs flagged in payload) — the class method wins over the trait.
 */
trait HasContents
{
    public function contents(): HasMany
    {
        return $this->hasMany(Cms::contentModel());
    }

    /**
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function visibleContents(?User $user = null, string|array|null $types = null): Collection
    {
        return Cms::contentModel()::query()
            ->visibleTo($this, $user)
            ->when($types !== null, fn ($query) => $query->ofType($types))
            ->orderBy('sort')
            ->orderBy('title')
            ->get();
    }
}
