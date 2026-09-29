<?php

namespace Mmoollllee\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301-redirects GET requests with a trailing slash to the canonical slash-less path.
 *
 * The legacy WordPress site used trailing slashes on every URL; the relaunched paths do not —
 * this preserves the old links' SEO value. Pure string work (no DB), so it stays cheap enough
 * to run on every request. Replaces the former app-local App\Http\Middleware\RedirectTrailingSlash.
 *
 * A path with upper-case letters passes through: stripping only the slash here would send
 * "/Kontakt/" to "/Kontakt" and leave the letter case for ContentShowController's own 301 — two
 * hops. Routing ignores a trailing slash, so the controller sees "/Kontakt" either way and
 * answers with the page's canonical path in one redirect.
 */
class CanonicalizeTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if ($request->isMethod('GET') && $path !== '/' && str_ends_with($path, '/') && ! $this->hasUpperCase($path)) {
            $query = $request->getQueryString();

            return redirect(rtrim($path, '/').($query !== null ? '?'.$query : ''), 301);
        }

        return $next($request);
    }

    /**
     * Decoded first: the upper-case hex of a percent escape ("%C3%9F") is not a letter.
     */
    protected function hasUpperCase(string $path): bool
    {
        $decoded = rawurldecode($path);

        return mb_strtolower($decoded) !== $decoded;
    }
}
