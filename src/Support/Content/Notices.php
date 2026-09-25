<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Sites\Notice\Blueprint;

/**
 * The notices a page shows ({@see Cms::enableNotices()}) — read by
 * <x-cms::notices />, and by any app view that renders them its own way.
 */
class Notices
{
    /**
     * The notices as the given user may see them, the latest window first: the
     * live ones — and, while a member previews, every notice, unpublished,
     * scheduled and expired ones included (visibleTo()). Notices without text
     * are skipped: an empty banner is worse than none. Empty while notices are
     * not enabled, so records left over from an earlier opt-in stay off the site.
     *
     * @return Collection<int, Model>
     */
    public static function shown(?Tenant $tenant, ?User $user = null): Collection
    {
        if ($tenant === null || ! Cms::hasNotices()) {
            return new Collection;
        }

        return Cms::contentModel()::query()
            ->visibleTo($tenant, $user)
            ->ofType(Blueprint::KEY)
            ->orderByDesc('publish_from')
            ->orderByDesc('id')
            ->get()
            ->reject(fn (Model $notice): bool => RichText::isBlank(data_get($notice->getAttribute('payload'), 'content')))
            ->values();
    }
}
