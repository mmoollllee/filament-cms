<?php

namespace Mmoollllee\Cms\Support\Content;

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug;
use Mmoollllee\Cms\Contracts\Content;

/**
 * Answers whether storing a content record's path would collide with a row that already
 * holds it under the same tenant — for the record itself AND for the subtree a rename
 * drags along.
 *
 * `contents` has a unique index on (tenant_id, path), and left to itself that index
 * answers in the harshest way there is: an uncaught UniqueConstraintViolationException,
 * i.e. a 500 with the edit lost. Every writer — the panel form, the Duplizieren action,
 * imports, seeders, console commands — goes through the model's saving hook
 * ({@see GeneratesPathAndSlug}), so the question is
 * asked there rather than only on the form, and asked BEFORE anything is written.
 *
 * The subtree half matters because a rename cascades: the saved hook re-saves every
 * child onto the new prefix, and those N writes are not the record the form validated.
 */
class PathConflicts
{
    /**
     * The first collision storing $content would cause: its own path, or a descendant the
     * cascade would move onto a path someone else holds. Null when the whole move fits.
     *
     * @return array{record: Content, path: string, owner: Content}|null
     */
    public function firstConflict(Content $content): ?array
    {
        $path = $content->getAttribute('path');

        // Non-routable types share a null path; the index does not constrain those.
        if (blank($path)) {
            return null;
        }

        // An unchanged path is already in the index and cannot newly collide — this keeps
        // an ordinary save free of extra queries.
        if ($content->exists && ! $content->isDirty('path')) {
            return null;
        }

        $renaming = $content->exists;

        // Everything the cascade moves keeps its old path only until it is rewritten, so
        // those rows must not count as owners of the paths being vacated.
        $moving = $renaming ? $this->subtreeKeys($content) : [$content->getKey()];

        $owner = $this->ownerOf($content, $path, $moving);

        if ($owner !== null) {
            return ['record' => $content, 'path' => $path, 'owner' => $owner];
        }

        if (! $renaming) {
            return null;
        }

        $claimed = [$path => $content];

        return $this->firstSubtreeConflict($content, $moving, $claimed);
    }

    /**
     * Walk the children of $parent as they WILL be stored, one level at a time.
     *
     * @param  array<int, mixed>  $moving
     * @param  array<string, Content>  $claimed  paths already taken by this same move
     * @return array{record: Content, path: string, owner: Content}|null
     */
    protected function firstSubtreeConflict(Content $parent, array $moving, array &$claimed): ?array
    {
        $children = Cms::contentModel()::query()
            ->where('parent_id', $parent->getKey())
            ->get();

        foreach ($children as $child) {
            // Compose against the parent as it will be stored, not as the database still
            // has it: seeding the relation is what makes PathGenerator (via resolvedPath)
            // use the NEW parent path instead of re-reading the old one.
            $probe = clone $child;
            $probe->setRelation('parent', $parent);

            $path = $probe->resolvedPath();

            if (blank($path)) {
                continue;
            }

            $owner = $claimed[$path] ?? $this->ownerOf($child, $path, $moving);

            if ($owner !== null && $owner->getKey() !== $child->getKey()) {
                return ['record' => $child, 'path' => $path, 'owner' => $owner];
            }

            $claimed[$path] = $child;

            $probe->setAttribute('path', $path);

            $conflict = $this->firstSubtreeConflict($probe, $moving, $claimed);

            if ($conflict !== null) {
                return $conflict;
            }
        }

        return null;
    }

    /**
     * The record already holding $path for this tenant, ignoring the rows that are moving
     * as part of the same save.
     *
     * @param  array<int, mixed>  $ignoreKeys
     */
    protected function ownerOf(Content $content, string $path, array $ignoreKeys): ?Content
    {
        return Cms::contentModel()::query()
            ->where('tenant_id', $content->getAttribute('tenant_id'))
            ->where('path', $path)
            ->whereKeyNot(array_values(array_filter($ignoreKeys)))
            ->first();
    }

    /**
     * $content plus every descendant, level by level. Guards against a malformed cycle in
     * `parent_id` rather than looping forever on it.
     *
     * @return array<int, mixed>
     */
    protected function subtreeKeys(Content $content): array
    {
        $keys = [$content->getKey()];
        $level = [$content->getKey()];

        while ($level !== []) {
            $level = Cms::contentModel()::query()
                ->whereIn('parent_id', $level)
                ->whereKeyNot($keys)
                ->pluck($content->getKeyName())
                ->all();

            $keys = [...$keys, ...$level];
        }

        return $keys;
    }
}
