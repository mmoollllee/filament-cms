<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Database\Eloquent\Collection;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug;
use Mmoollllee\Cms\Contracts\Content;

/**
 * The two questions everything that moves a path has to ask, asked in one place.
 *
 * - "Who holds this address?" — {@see ownerOf()}
 * - "Which rows does this move touch?" — {@see childrenOf()} / {@see subtreeKeys()}
 *
 * Both were answered by their own inline query at every call site: the collision guard,
 * the rename redirects, the cascade in {@see GeneratesPathAndSlug} and the redirect form's
 * shadowing hint. Each of those queries has the same two easy-to-forget parts — scope by
 * tenant (nothing validates `parent_id` against one, so an adopted foreign row would
 * otherwise be dragged along by this tenant's rename) and ignore the rows that are moving
 * with the save (they hold their old address only until they are rewritten).
 *
 * Ownership deliberately ignores visibility: the `(tenant_id, path)` unique index does
 * too, so a draft or an expired page holds its address just as hard as a live one. The
 * "which page does a visitor get here" question is a different one and stays in
 * {@see ContentResolver}.
 */
class ContentTree
{
    /**
     * The record holding $path for $tenantId, or null when the address is free.
     *
     * @param  array<int, mixed>  $ignoreKeys  rows moving as part of the same save
     */
    public function ownerOf(int|string|null $tenantId, ?string $path, array $ignoreKeys = []): ?Content
    {
        // A null tenant would match across the whole table; a blank path is what every
        // non-routable row stores, and the index does not constrain those.
        if ($tenantId === null || blank($path)) {
            return null;
        }

        return Cms::contentModel()::query()
            ->where('tenant_id', $tenantId)
            ->where('path', $path)
            ->whereKeyNot(array_values(array_filter($ignoreKeys)))
            ->first();
    }

    /**
     * The direct children of $parent, within $parent's own tenant.
     *
     * @return Collection<int, Content>
     */
    public function childrenOf(Content $parent): Collection
    {
        return $this->childrenOfAny($parent->getAttribute('tenant_id'), [$parent->getKey()]);
    }

    /**
     * The children $keys would leave behind if those rows were deleted: direct children
     * that are not themselves in the deletion.
     *
     * `parent_id` is `nullOnDelete`, so the database re-parents exactly these rows to the
     * root — as a plain UPDATE that fires no model events. Deeper descendants stay attached
     * to their own parent. Named for the question rather than aliased away: "what does this
     * delete strand" is not the same question as "what is underneath this", even where the
     * query is.
     *
     * @param  array<int, mixed>  $keys
     * @return Collection<int, Content>
     */
    public function orphansOf(int|string|null $tenantId, array $keys): Collection
    {
        return $this->childrenOfAny($tenantId, $keys);
    }

    /**
     * The rows whose parent is one of $keys, excluding $keys themselves.
     *
     * The one query behind every question this class answers, so the two parts that are
     * easy to forget — scope by tenant, and never count a row that is part of the set
     * being asked about — are written once.
     *
     * @param  array<int, mixed>  $keys
     * @return Collection<int, Content>
     */
    protected function childrenOfAny(int|string|null $tenantId, array $keys): Collection
    {
        $keys = array_values(array_filter($keys));

        if ($tenantId === null || $keys === []) {
            return new Collection;
        }

        return Cms::contentModel()::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('parent_id', $keys)
            ->whereKeyNot($keys)
            ->get();
    }

    /**
     * $root plus every descendant, level by level — the rows a rename of $root drags
     * along. Guards against a malformed cycle in `parent_id` rather than looping forever
     * on it: the tree is a tree by convention, nothing in the schema enforces it.
     *
     * @return array<int, mixed>
     */
    public function subtreeKeys(Content $root): array
    {
        // A record that does not exist yet has no key and therefore no descendants;
        // whereKeyNot([null]) would compare against NULL and quietly match nothing.
        $keys = array_values(array_filter([$root->getKey()]));
        $level = $keys;

        while ($level !== []) {
            $level = $this->childrenOfAny($root->getAttribute('tenant_id'), $level)
                ->modelKeys();

            // Everything already seen is dropped here rather than in the query: a deep
            // import would otherwise bind the whole accumulated key set as parameters at
            // every level, past SQLite's variable ceiling.
            $level = array_values(array_diff($level, $keys));

            $keys = [...$keys, ...$level];
        }

        return $keys;
    }
}
