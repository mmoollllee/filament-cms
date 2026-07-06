<?php

use Illuminate\Support\Facades\Route;
use Mmoollllee\Cms\Http\Controllers\Frontend\ContentFragmentController;
use Mmoollllee\Cms\Http\Controllers\Frontend\ContentShowController;
use Mmoollllee\Cms\Http\Controllers\Frontend\RobotsController;
use Mmoollllee\Cms\Http\Controllers\Frontend\SitemapController;
use Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost;

/**
 * Frontend routes — tenant-scoped by host (ResolveTenantFromHost). Mirrors a
 * consuming app's routes/web.php so the panel's "visit site" link resolves and
 * the multi-tenant frontend renders.
 */
Route::middleware(ResolveTenantFromHost::class)->group(function (): void {
    Route::get('/robots.txt', RobotsController::class)->name('robots');
    Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
    Route::get('/_content', ContentFragmentController::class)->name('content.fragment');

    // Placeholder image for the Media-block demo on the docs site (self-contained,
    // so the block renders a real image without shipping binary assets).
    Route::get('/demo-media.svg', function () {
        $svg = <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 600">
                <defs>
                    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#d97706"/>
                        <stop offset="1" stop-color="#7c2d12"/>
                    </linearGradient>
                </defs>
                <rect width="1200" height="600" fill="url(#g)"/>
                <text x="50%" y="48%" fill="#fff" font-family="system-ui,sans-serif" font-size="64" font-weight="800" text-anchor="middle">filament-cms</text>
                <text x="50%" y="60%" fill="#ffffffcc" font-family="system-ui,sans-serif" font-size="30" text-anchor="middle">Media-Block · Bild oder Video</text>
            </svg>
            SVG;

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    })->name('demo-media');

    Route::get('/{path?}', ContentShowController::class)
        ->where('path', '.*')
        ->name('content.show');
});
