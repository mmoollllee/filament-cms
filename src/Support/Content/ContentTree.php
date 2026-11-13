<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Database\Eloquent\Collection;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Rules\ValidParent;

/**
 * Every question that walks the content tree, asked in one place.
 *
 * - "Who holds this address?" — {@see ownerOf()}
 * - "Which row is the parent, and the parent's parent?" — {@see parentOf()} / {@see ancestorsOf()}
 * - "Which rows does this move touch?" — {@see childrenOf()} / {@see subtreeKeys()}
 *
 * Each of these used to be answered by its own inline query or loop at every call site —
 * the collision guard, the rename redirects, the cascade in {@see GeneratesPathAndSlug},
 * the breadcrumbs, the onepager lookup, the table indent — and each walk carried its own
 * version of the same two easy-to-forget parts, or forgot them:
 *
 * - scope by tenant. The write path refuses a foreign parent ({@see ValidParent}), but rows
 *   written past it (an import, a raw query) can still carry one, and an eager-loaded
 *   relation is not scoped at all;
 * - survive a cycle. `parent_id` is a tree by convention only; nothing in the schema stops
 *   it pointing back into the chain, and an unguarded walk loops until the request dies.
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
     * The row with $key in $tenantId — never another tenant's, whatever key is asked for.
     */
    public function find(int|string|null $tenantId, mixed $key): ?Content
    {
        if ($tenantId === null || blank($key)) {
            return null;
        }

        return Cms::contentModel()::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($key)
            ->first();
    }

    /**
     * $content's parent: the row `parent_id` names, if it belongs to $content's own tenant.
     *
     * A loaded `parent` relation is used as it is when it is that row and of that tenant —
     * lists eager-load the chain (the content table's indent, the resolver) and have to stay
     * free of queries. Anything else is replaced by the scoped answer: a relation loaded
     * before `parent_id` changed (a form probing a new parent), or one reaching into another
     * tenant, which an eager load cannot rule out. The answer is kept on the model the way a
     * lazy load would keep it.
     */
    public function parentOf(Content $content): ?Content
    {
        $parentId = $content->getAttribute('parent_id');
        $tenantId = $content->getAttribute('tenant_id');

        if (blank($parentId) || $tenantId === null) {
            return null;
        }

        if ($content->relationLoaded('parent')) {
            $loaded = $content->getRelation('parent');

            if ($loaded instanceof Content
                && (string) $loaded->getKey() === (string) $parentId
                && (string) $loaded->getAttribute('tenant_id') === (string) $tenantId) {
                return $loaded;
            }
        }

        $parent = $this->find($tenantId, $parentId);

        $content->setRelation('parent', $parent);

        return $parent;
    }

    /**
     * $content's ancestors, nearest first — the one walk up the tree.
     *
     * Tenant-scoped at every step ({@see parentOf()}), and it stops where the chain comes
     * back to a row it has already passed: a malformed cycle ends the walk instead of the
     * request. $limit stops it early for callers that only ever look a few levels up.
     *
     * @return list<Content>
     */
    public function ancestorsOf(Content $content, ?int $limit = null): array
    {
        $seen = filled($content->getKey()) ? [(string) $content->getKey() => true] : [];
        $ancestors = [];
        $current = $content;

        // The limit is checked before the next parent is asked for, so a list that
        // eager-loaded exactly $limit levels never loads one more.
        while ($limit === null || count($ancestors) < $limit) {
            $parent = $this->parentOf($current);

            if ($parent === null || isset($seen[(string) $parent->getKey()])) {
                break;
            }

            $seen[(string) $parent->getKey()] = true;
            $ancestors[] = $parent;
            $current = $parent;
        }

        return $ancestors;
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
     * $root plus every descendant, level by level — the rows a rename of $root drags along.
     *
     * @return array<int, mixed>
     */
    public function subtreeKeys(Content $root): array
    {
        return $this->subtreeKeysOf($root->getAttribute('tenant_id'), $root->getKey());
    }

    /**
     * $rootKey plus every key below it in $tenantId, nearest levels first.
     *
     * Walked over one `id → parent_id` map of the tenant rather than one query per level —
     * content sets per tenant are small, and a deep import would otherwise bind the whole
     * accumulated key set as parameters at every level, past SQLite's variable ceiling. A
     * row already collected is never queued again, which is what ends a malformed cycle.
     *
     * @return array<int, mixed>
     */
    public function subtreeKeysOf(int|string|null $tenantId, mixed $rootKey): array
    {
        // A record that does not exist yet has no key and therefore no descendants.
        if ($tenantId === null || blank($rootKey)) {
            return [];
        }

        $model = Cms::contentModel();

        $childrenByParent = [];

        foreach ($model::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('parent_id')
            ->pluck('parent_id', (new $model)->getKeyName()) as $key => $parentKey) {
            $childrenByParent[(string) $parentKey][] = $key;
        }

        $keys = [$rootKey];
        $collected = [(string) $rootKey => true];

        for ($index = 0; $index < count($keys); $index++) {
            foreach ($childrenByParent[(string) $keys[$index]] ?? [] as $childKey) {
                if (! isset($collected[(string) $childKey])) {
                    $collected[(string) $childKey] = true;
                    $keys[] = $childKey;
                }
            }
        }

        return $keys;
    }

    /**
     * The rows whose parent is one of $keys, excluding $keys themselves.
     *
     * The one query behind the child questions this class answers, so the two parts that
     * are easy to forget — scope by tenant, and never count a row that is part of the set
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
}
