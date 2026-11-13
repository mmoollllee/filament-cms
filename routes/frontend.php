<?php

use Illuminate\Support\Facades\Route;
use Mmoollllee\Cms\Http\Controllers\Frontend\ResolveNotFoundController;
use Mmoollllee\Cms\Http\Middleware\EnsureTenantIsVisible;
use Mmoollllee\Cms\Http\Middleware\ResolveTenantFromHost;

/*
| Package frontend routes, loaded via CmsServiceProvider::loadRoutesFrom() during provider boot
| so they register BEFORE the app's catch-all `/{path?}` route and are matched first. Both
| consuming apps get these with no per-app wiring.
*/

Route::middleware('web')->group(function (): void {
    // Async "parallel auto-resolution" endpoint called by the branded 404 page.
    //
    // It names the tenant middleware itself rather than trusting the app to have put it on
    // the `web` group: an app that scopes it to its own frontend route group instead would
    // leave this endpoint without a tenant, answering every 404 with no suggestions at all.
    // Where the app does append it to `web`, Laravel de-duplicates the stack, so it still
    // runs once. The visibility gate belongs with it — suggestions quote page titles.
    Route::get('/_resolve404', ResolveNotFoundController::class)
        ->middleware([ResolveTenantFromHost::class, EnsureTenantIsVisible::class, 'throttle:60,1'])
        ->name('cms.resolve-not-found');

    // The invitation accept endpoint (`/_invitation/{token}`) is registered by
    // filament-tenant-access under `tenant-access.invitations.accept`; its path
    // and middleware are configured in CmsServiceProvider::configureTenantAccess().
});
