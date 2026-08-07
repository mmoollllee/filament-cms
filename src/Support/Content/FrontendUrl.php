<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Support\Facades\Route;

/**
 * Builds absolute frontend URLs from content paths via the app's catch-all
 * `content.show` route — the single place that knows how a path becomes a URL.
 *
 * The URL is generated on the CURRENT host, which is what every caller wants:
 * the panel is domain-scoped per tenant (`tenantDomain('{tenant:primary_domain}')`),
 * so a link built while editing on pernes-hebesysteme.de.test points at that
 * site's frontend.
 *
 * Returns null when the app registers no `content.show` route (headless installs,
 * package tests without the workbench frontend), so callers can hide their link
 * instead of crashing on route generation.
 */
class FrontendUrl
{
    /**
     * Absolute URL for a content path — '/' and '' are the homepage, a leading
     * slash is optional. A null path means "this record has no frontend page"
     * and yields null, so callers can hide their link instead of silently
     * pointing it at the homepage.
     *
     * @param  array<string, mixed>  $query  extra query parameters (e.g. preview mode)
     */
    public static function forPath(?string $path, array $query = []): ?string
    {
        if ($path === null || ! Route::has('content.show')) {
            return null;
        }

        $path = ltrim($path, '/');

        // No array_filter/blank() here: it would also drop the legitimate path '0'.
        if ($path !== '') {
            $query['path'] = $path;
        }

        return route('content.show', $query);
    }
}
