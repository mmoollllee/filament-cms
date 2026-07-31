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
 * The panel lists the current tenant's pages and sections. Project types stay out
 * on purpose: the picker is unpaginated, so a type with many records (machines,
 * job ads, articles) would bury the handful of records a navigation is actually
 * built from. Add the ones that ARE menu targets in the host model:
 *
 * ```php
 * use ProvidesMenuPanel {
 *     menuPanelContentTypes as defaultMenuPanelContentTypes;
 * }
 *
 * protected function menuPanelContentTypes(): array
 * {
 *     return [...$this->defaultMenuPanelContentTypes(), 'jobs.job'];
 * }
 * ```
 *
 * The host model needs `title`, `path`, `sort`, `content_type` and `tenant_id`
 * columns.
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
     * `path` is null for non-routable blueprints: no URL, nothing to link to.
     */
    public function getMenuPanelQuery(): Builder
    {
        $tenant = app(CurrentTenant::class)->get();

        return $this->newQuery()
            ->when($tenant !== null, fn (Builder $query): Builder => $query->where('tenant_id', $tenant->getKey()))
            ->whereIn('content_type', $this->menuPanelContentTypes())
            ->whereNotNull('path')
            ->orderBy('sort')
            ->orderBy('title');
    }

    /**
     * Content types the panel offers — the package's own routable defaults.
     *
     * @return list<string>
     */
    protected function menuPanelContentTypes(): array
    {
        return ['default.page', 'default.section'];
    }
}
