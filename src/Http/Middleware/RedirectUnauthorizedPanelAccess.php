<?php

namespace Mmoollllee\Cms\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\Response;

class RedirectUnauthorizedPanelAccess extends FilamentAuthenticate
{
    /**
     * @param  array<string>  $guards
     *
     * Handle an incoming request.
     */
    public function handle($request, Closure $next, ...$guards): Response
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->authenticate($request, $guards);

            return $next($request);
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        /** @var Model $user */
        $user = $guard->user();

        if (! $user instanceof FilamentUser) {
            abort_if(! app()->isLocal(), 403);

            return $next($request);
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        if ($user->canAccessPanel($panel)) {
            return $next($request);
        }

        $defaultTenant = Filament::getUserDefaultTenant($user);
        $defaultUrl = $panel->getUrl($defaultTenant);

        if (filled($defaultUrl) && $defaultUrl !== $request->fullUrl()) {
            return redirect()->to($defaultUrl);
        }

        abort(403);
    }
}
