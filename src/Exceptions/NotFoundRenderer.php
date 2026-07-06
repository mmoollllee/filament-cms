<?php

namespace Mmoollllee\Cms\Exceptions;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Routing\HitRecorder;
use Mmoollllee\Cms\Support\Routing\PathNormalizer;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Turns a frontend 404 into the tenant-branded error page and records the miss.
 *
 * Wired in the app's bootstrap/app.php via withExceptions()->render(NotFoundHttpException, …).
 * Handling ALL NotFoundHttpExceptions here (rather than only in the catch-all controller) means
 * the branded page + 404 collection cover every source uniformly. The tenant is passed to the
 * view explicitly, so branding works even when the consuming app has no view composer for the
 * error view.
 *
 * Returns null to fall through to Laravel's default rendering for: panel requests, JSON/API
 * requests, and requests with no resolved tenant (unknown host → no branding possible).
 */
class NotFoundRenderer
{
    public function __construct(
        protected CurrentTenant $currentTenant,
        protected HitRecorder $hitRecorder,
        protected PathNormalizer $normalizer,
        protected ViewFactory $views,
    ) {}

    public function handle(Request $request, Throwable $exception): ?Response
    {
        $panelPath = Cms::panelPath();

        if ($request->expectsJson() || $request->is($panelPath, $panelPath.'/*')) {
            return null;
        }

        $tenant = $this->currentTenant->get();

        if ($tenant === null) {
            return null;
        }

        $path = $this->normalizer->normalize($request->getPathInfo());

        $this->hitRecorder->record404(
            $tenant,
            $path,
            $request->headers->get('referer'),
            $request->userAgent(),
        );

        return response()->view($this->errorView($tenant), [
            'tenant' => $tenant,
            'requestedPath' => $path,
            'homeUrl' => '/',
            'resolveUrl' => Route::has('cms.resolve-not-found')
                ? route('cms.resolve-not-found')
                : url('/_resolve404'),
        ], 404);
    }

    /**
     * Prefer a site-specific error view ("{site_key}.errors.404") when the app ships one,
     * mirroring the content TemplateResolver's per-site convention; otherwise fall back to
     * the shared branded default. The per-site view typically `@extends('cms::errors.404')`
     * and only overrides the branded seams, so it never has to copy the whole page.
     */
    protected function errorView(Tenant $tenant): string
    {
        $siteKey = $tenant->site_key;

        if (filled($siteKey)) {
            $candidate = "{$siteKey}.errors.404";

            if ($this->views->exists($candidate)) {
                return $candidate;
            }
        }

        return 'cms::errors.404';
    }
}
