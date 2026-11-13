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
 *
 * The frontend-url methods come from {@see HasFrontendUrl} and are implemented by
 * {@see \Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug} — the same trait that
 * supplies resolvedPath() and generatedPath(), so app models get them without a code change.
 */
interface Content extends HasFrontendUrl
{
    /** The URL path of this content: its stored `path` (null for non-routable types). */
    public function resolvedPath(): ?string;

    /** The path a save would store for this content as it stands — the generator's answer. */
    public function generatedPath(): ?string;

    /**
     * The frontend path this record is reachable at: its own path, or — for
     * non-routable types — the parent page that embeds it. Null when neither exists.
     */
    public function frontendPath(): ?string;
}
