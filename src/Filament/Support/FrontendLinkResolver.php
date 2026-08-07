<?php

namespace Mmoollllee\Cms\Filament\Support;

use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Contracts\HasFrontendUrl;
use Mmoollllee\Cms\Support\Content\FrontendUrl;

/**
 * Resolves the target for the topbar "Öffnen" button: it points at the public
 * frontend page of the record currently open in the panel (e.g. /datenschutz
 * while editing that page) and falls back to the site's homepage.
 *
 * Resolution is route-based on purpose. The button is rendered inside the
 * persistent global-search Livewire component, which does not know the page
 * record; but since the panel does not use SPA navigation, every page is a full
 * server render where the current route reliably reflects the open record.
 *
 * The record's model is not hard-coded: the route name identifies the panel
 * resource, which yields the model — so a site extension's own resource gets the
 * button for free as soon as its model implements {@see HasFrontendUrl}.
 */
class FrontendLinkResolver
{
    /**
     * Resolve the URL and label for the topbar button on the current page.
     *
     * @return array{url: string|null, label: string}
     */
    public static function forCurrentRoute(): array
    {
        $route = request()->route();

        return static::forRoute($route instanceof Route ? $route : null);
    }

    /**
     * Resolve the URL and label for the topbar button on the given route. A null
     * url means the button should not be rendered (an app without a `content.show`
     * frontend route).
     *
     * @return array{url: string|null, label: string}
     */
    public static function forRoute(?Route $route): array
    {
        $record = static::recordForRoute($route);

        return [
            'url' => $record?->getFrontendUrl() ?? FrontendUrl::forPath('/'),
            'label' => __('Öffnen'),
        ];
    }

    /**
     * Resolve the record bound to the given resource route, when it is one whose
     * records have a public frontend page.
     */
    protected static function recordForRoute(?Route $route): ?HasFrontendUrl
    {
        $key = $route?->parameter('record');
        $resource = static::resourceForRoute($route);

        if ((! is_string($key) && ! is_int($key)) || $resource === null) {
            return null;
        }

        // Ask the MODEL before touching the database: resolving runs the
        // resource's list-table base query (eager loads, counts), which is pure
        // waste on the resources whose records have no frontend page at all.
        if (! is_a($resource::getModel(), HasFrontendUrl::class, true)) {
            return null;
        }

        // Resolved through the RESOURCE, not the model: its route binding honors
        // getRecordRouteKeyName() and the resource's own base query, so the button
        // links the same record the page itself opened.
        $record = $resource::resolveRecordRouteBinding($key);

        // A resource may drop the soft-delete scope to keep trashed records
        // editable (Filament's TrashedFilter workflow). Their frontend page is
        // gone, so they fall through to the homepage instead of a dead link.
        if ($record instanceof Model && method_exists($record, 'trashed') && $record->trashed()) {
            return null;
        }

        return $record instanceof HasFrontendUrl ? $record : null;
    }

    /**
     * The Filament resource owning the given route.
     *
     * Filament registers every resource page with the page class as the route
     * action, so the route names its own page and the page names its resource —
     * no route-name parsing, and a nested resource answers for itself instead of
     * being shadowed by its parent's route prefix.
     *
     * @return class-string|null
     */
    protected static function resourceForRoute(?Route $route): ?string
    {
        $page = $route?->getAction('controller');

        if (! is_string($page)) {
            return null;
        }

        $page = Str::before($page, '@');

        return is_subclass_of($page, ResourcePage::class) ? $page::getResource() : null;
    }
}
