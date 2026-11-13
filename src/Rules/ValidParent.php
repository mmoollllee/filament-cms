<?php

namespace Mmoollllee\Cms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Support\Content\ContentTree;

/**
 * The tree's invariants for one record's `parent_id`, checked where every writer passes —
 * the saving hook in {@see GeneratesPathAndSlug} — and not only in the parent Select,
 * whose option list is a UI affordance a hidden field, an import or a revision restore
 * never meets:
 *
 * - the parent is a row of the record's own tenant. Otherwise the record composes its
 *   path from another tenant's structure, and its breadcrumbs quote that tenant's titles;
 * - it is neither the record itself nor one of its descendants. Otherwise `parent_id`
 *   closes a cycle — a restored revision can name a parent that has since moved below it;
 * - its type is one the blueprint lists in allowedParentTypes() — WHEN it lists any. A
 *   type with a urlPathPrefix lists none, because the prefix owns its URL, and sites still
 *   hang such records below a page for structure the URL never reads: jobs below their
 *   listing section, rental categories below their overview. An empty list therefore
 *   means "not constrained here", not "no parent allowed".
 */
class ValidParent implements ValidationRule
{
    public function __construct(
        protected Content $content,
        protected ?ContentBlueprint $blueprint = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $tree = app(ContentTree::class);

        $parent = $tree->find($this->content->getAttribute('tenant_id'), $value);

        if ($parent === null) {
            $fail('Die übergeordnete Seite gibt es auf dieser Website nicht.');

            return;
        }

        if ($this->content->exists
            && in_array((string) $parent->getKey(), array_map('strval', $tree->subtreeKeys($this->content)), true)) {
            $fail('Eine Seite kann nicht unter sich selbst oder einer ihrer Unterseiten liegen.');

            return;
        }

        $allowed = $this->blueprint?->allowedParentTypes() ?? [];

        if ($allowed !== [] && ! in_array($parent->getAttribute('content_type'), $allowed, true)) {
            $fail(sprintf(
                'Unter „%s“ können keine %s liegen.',
                $parent->getAttribute('title'),
                $this->blueprint->pluralLabel(),
            ));
        }
    }
}
