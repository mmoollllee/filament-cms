<?php

namespace Mmoollllee\Cms\Concerns\Content;

use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Feeds the menu-builder's "Inhalte" panel — the list an editor drags links from
 * when building a navigation — and resolves title + URL for the items linked to a
 * content record. Implements every method of
 * {@see \Datlechin\FilamentMenuBuilder\Contracts\MenuPanelable}, so the host model
 * only declares the interface.
 *
 * URLs are resolved live via {@see \Mmoollllee\Cms\Concerns\Content\GeneratesPathAndSlug::resolvedPath()}
 * on every read (the plugin reads `MenuItem::$url` through the linkable), which is
 * why renaming or moving a page never leaves a stale link behind.
 *
 * The panel lists every routable content of the current tenant (non-routable
 * blueprints have no `path` and therefore no URL to link to). To narrow it
 * further, alias the trait method in the host model and build on it:
 *
 * ```php
 * use ProvidesMenuPanel {
 *     getMenuPanelQuery as tenantMenuPanelQuery;
 * }
 *
 * public function getMenuPanelQuery(): Builder
 * {
 *     return $this->tenantMenuPanelQuery()
 *         ->whereIn('content_type', ['default.page', 'default.section']);
 * }
 * ```
 *
 * The host model needs `title`, `path`, `sort` and `tenant_id` columns.
 */
trait ProvidesMenuPanel
{
    /** Panel heading in the menu editor. */
    public function getMenuPanelName(): string
    {
        return 'Inhalte';
    }

    /** Label the menu item gets when the content is dragged into a menu. */
    public function getMenuPanelTitle(): string
    {
        return (string) $this->title;
    }

    /** Live URL of the linked content; '/' for records that lost their path. */
    public function getMenuPanelUrl(): string
    {
        return $this->resolvedPath() ?? '/';
    }

    /**
     * The linkable records, scoped to the current tenant — the panel is rendered
     * inside the admin panel, where an editor may only link their own content.
     */
    public function getMenuPanelQuery(): Builder
    {
        $tenant = app(CurrentTenant::class)->get();

        return $this->newQuery()
            ->when($tenant !== null, fn (Builder $query): Builder => $query->where('tenant_id', $tenant->getKey()))
            ->whereNotNull('path')
            ->orderBy('sort')
            ->orderBy('title');
    }
}
