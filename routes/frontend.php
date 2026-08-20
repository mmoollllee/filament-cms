<?php

use Illuminate\Support\Facades\Route;
use Mmoollllee\Cms\Http\Controllers\Frontend\ResolveNotFoundController;
use Mmoollllee\Cms\Http\Controllers\TenantInvitationController;

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

    // Accept a tenant invitation. The signature bounds the link by the
    // invitation's own expiry (URL::signedRoute in TenantInvitation::acceptUrl),
    // so a stale link is refused before any state is read. `relative` because
    // the link is signed on the tenant's own host rather than app.url — see
    // acceptUrl(). The tenant-visibility gate exempts this route by name: a
    // members-only site is exactly the kind whose invitees have to get in.
    Route::get('/_invitation/{token}', [TenantInvitationController::class, 'accept'])
        ->middleware(['signed:relative', 'throttle:30,1'])
        ->name('cms.tenant-invitations.accept');
});
