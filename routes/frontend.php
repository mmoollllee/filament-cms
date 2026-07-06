<?php

use Illuminate\Support\Facades\Route;
use Mmoollllee\Cms\Http\Controllers\Frontend\ResolveNotFoundController;

/*
| Package frontend routes, loaded via CmsServiceProvider::loadRoutesFrom() during provider boot
| so they register BEFORE the app's catch-all `/{path?}` route and are matched first. Both
| consuming apps get these with no per-app wiring.
*/

Route::middleware('web')->group(function (): void {
    // Async "parallel auto-resolution" endpoint called by the branded 404 page.
    Route::get('/_resolve404', ResolveNotFoundController::class)
        ->middleware('throttle:60,1')
        ->name('cms.resolve-not-found');
});
