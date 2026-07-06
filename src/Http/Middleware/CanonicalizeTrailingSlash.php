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
 */
class CanonicalizeTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if ($request->isMethod('GET') && $path !== '/' && str_ends_with($path, '/')) {
            $query = $request->getQueryString();

            return redirect(rtrim($path, '/').($query !== null ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
