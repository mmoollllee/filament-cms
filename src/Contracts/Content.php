<?php

namespace Mmoollllee\Cms\Contracts;

/**
 * Contract implemented by an application's content model (the concrete class is
 * resolved via Cms::contentModel()).
 *
 * The CMS engine (resolvers, path generation, blueprints) types against this
 * contract instead of a concrete Eloquent class so the model stays owned by the
 * application. Eloquent attributes the engine reads at runtime — content_type,
 * path, slug, title, template, payload, blocks, parent_id, tenant_id — and the
 * tenant/parent/children relations are not declared here; this contract only
 * pins the domain methods the engine calls explicitly.
 */
interface Content
{
    /** Resolved URL path for this content (stored `path`, or blueprint-generated). */
    public function resolvedPath(): ?string;
}
