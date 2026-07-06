<?php

namespace Mmoollllee\Cms\Support\Content;

use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;

/**
 * Builds the navigation context array consumed by Alpine.js navigation components.
 *
 * The returned array drives:
 * - Breadcrumb rendering (mode: 'none' | 'child' | 'standalone')
 * - Local section navigation (anchor links within a page)
 * - Flyout menu indicator label
 *
 * Breadcrumb modes:
 * - 'none':       Top-level onepager sections (no breadcrumbs shown)
 * - 'child':      Nested content with a parent chain (full breadcrumb trail)
 * - 'standalone': Top-level section without onepager shell (shows own title only)
 *
 * The context is serialized as JSON and passed to `x-data="siteOnepager(...)"` or
 * `x-data="siteChildNavigation(...)"` in the header-menu Blade partial.
 */
class NavigationContextBuilder
{
    public function __construct(
        protected ContentResolver $contentResolver,
    ) {}

    /**
     * @return array{
     *     breadcrumbMode: 'none'|'child'|'standalone',
     *     homePath: string,
     *     rootPath: string|null,
     *     rootLabel: string,
     *     indicatorLabel: string,
     *     currentPath: string|null,
     *     breadcrumbs: list<array{label: string, path: string|null, isCurrent: bool}>,
     *     localSections: list<array{id: string, label: string, href: string}>,
     *     blockAnchors: array<int, array{id: string, label: string, href: string}>
     * }
     */
    public function build(Tenant $tenant, Content $content): array
    {
        $ancestorTrail = $this->ancestorTrail($content);
        $rootSection = $this->contentResolver->onepagerSectionFor($content, $tenant);
        $indicatorContent = $rootSection ?? $ancestorTrail[0] ?? $content;
        $currentPath = $content->resolvedPath();
        $breadcrumbMode = $this->breadcrumbMode($content, $tenant, $ancestorTrail, $rootSection);

        $localSectionContext = $this->buildLocalSectionContext($content);

        return [
            'breadcrumbMode' => $breadcrumbMode,
            'homePath' => '/',
            'rootPath' => $indicatorContent->resolvedPath(),
            'rootLabel' => $indicatorContent->title,
            'indicatorLabel' => $content->title ?: $tenant->displayName(),
            'currentPath' => $currentPath,
            'breadcrumbs' => $this->buildBreadcrumbs($ancestorTrail, $content, $breadcrumbMode === 'standalone'),
            'localSections' => in_array($breadcrumbMode, ['child', 'standalone'], true) && count($localSectionContext['sections']) >= 2
                ? $localSectionContext['sections']
                : [],
            'blockAnchors' => $localSectionContext['blockAnchors'],
        ];
    }

    /**
     * The indicator/flyout subset the onepager section payload needs — the same four fields
     * `build()` produces, but WITHOUT the breadcrumb, ancestor-breadcrumb and local-section work
     * that the section payload discards (and that runs once per section on an onepager render).
     *
     * @return array{indicatorLabel: string, rootPath: string|null, currentPath: string|null, homePath: string}
     */
    public function indicatorContext(Tenant $tenant, Content $content): array
    {
        $rootSection = $this->contentResolver->onepagerSectionFor($content, $tenant);
        $indicatorContent = $rootSection ?? ($this->ancestorTrail($content)[0] ?? $content);

        return [
            'indicatorLabel' => $content->title ?: $tenant->displayName(),
            'rootPath' => $indicatorContent->resolvedPath(),
            'currentPath' => $content->resolvedPath(),
            'homePath' => '/',
        ];
    }

    /**
     * @param  array{
     *     breadcrumbMode?: string,
     *     breadcrumbs?: list<array{label: string, path: string|null, isCurrent: bool}>
     * }  $navigationContext
     */
    public function showsBreadcrumbs(array $navigationContext, bool $showStandaloneBreadcrumbs = true): bool
    {
        $breadcrumbs = $navigationContext['breadcrumbs'] ?? [];

        if ($breadcrumbs === []) {
            return false;
        }

        return match ($navigationContext['breadcrumbMode'] ?? 'none') {
            'child' => true,
            'standalone' => $showStandaloneBreadcrumbs,
            default => false,
        };
    }

    /**
     * The breadcrumb label trail for a content, from its top-most ancestor down to itself —
     * e.g. ["Products", "Category"]. Reuses the same ancestor resolution as the frontend
     * breadcrumbs (parent_id chain, falling back to URL-path segments). Blank titles are dropped.
     *
     * @return list<string>
     */
    public function breadcrumbLabelsFor(Content $content): array
    {
        $labels = array_map(
            fn (Content $ancestor): string => trim((string) $ancestor->title),
            $this->ancestorTrail($content),
        );

        $labels[] = trim((string) $content->title);

        return array_values(array_filter($labels, fn (string $label): bool => $label !== ''));
    }

    /**
     * @return list<Content>
     */
    protected function ancestorTrail(Content $content): array
    {
        $ancestors = [];
        $currentAncestor = $content->parent;

        while ($currentAncestor instanceof Content) {
            array_unshift($ancestors, $currentAncestor);
            $currentAncestor = $currentAncestor->parent;
        }

        if ($ancestors !== []) {
            return $ancestors;
        }

        return $this->ancestorTrailFromPath($content);
    }

    /**
     * Resolve ancestors by walking the URL path segments upward.
     *
     * Covers content types that use `urlPathPrefix` instead of `parent_id`
     * (e.g. `/blog/my-first-post` → finds `/blog`).
     *
     * @return list<Content>
     */
    protected function ancestorTrailFromPath(Content $content): array
    {
        $path = $content->resolvedPath();

        if ($path === null || $path === '/' || ! str_contains($path, '/')) {
            return [];
        }

        $segments = explode('/', ltrim($path, '/'));
        array_pop($segments);

        if ($segments === []) {
            return [];
        }

        // Build every ancestor path prefix (/a, /a/b, …) and resolve them in ONE query
        // (keyed by path) instead of one query per segment.
        $ancestorPaths = [];
        $accumulated = '';

        foreach ($segments as $segment) {
            $accumulated .= '/'.$segment;
            $ancestorPaths[] = $accumulated;
        }

        $byPath = Cms::contentModel()::query()
            ->where('tenant_id', $content->tenant_id)
            ->whereIn('path', $ancestorPaths)
            ->get()
            ->keyBy('path');

        $ancestors = [];

        foreach ($ancestorPaths as $ancestorPath) {
            $ancestor = $byPath->get($ancestorPath);

            if ($ancestor !== null) {
                $ancestors[] = $ancestor;
            }
        }

        return $ancestors;
    }

    /**
     * @param  list<Content>  $ancestorTrail
     * @return list<array{label: string, path: string|null, isCurrent: bool}>
     */
    protected function buildBreadcrumbs(array $ancestorTrail, Content $content, bool $includeCurrentPage = false): array
    {
        if ($ancestorTrail === []) {
            if (! $includeCurrentPage) {
                return [];
            }

            return [[
                'label' => $content->title,
                'path' => $content->resolvedPath(),
                'isCurrent' => true,
            ]];
        }

        return array_values(array_filter([
            ...array_map(
                fn (Content $ancestor): array => [
                    'label' => $ancestor->title,
                    'path' => $ancestor->resolvedPath(),
                    'isCurrent' => false,
                ],
                $ancestorTrail,
            ),
            [
                'label' => $content->title,
                'path' => $content->resolvedPath(),
                'isCurrent' => true,
            ],
        ]));
    }

    /**
     * @return array{
     *     sections: list<array{id: string, label: string, href: string}>,
     *     blockAnchors: array<int, array{id: string, label: string, href: string}>
     * }
     */
    protected function buildLocalSectionContext(Content $content): array
    {
        $usedIds = [];
        $sections = [];
        $blockAnchors = [];

        foreach (array_values($content->blocks ?? []) as $index => $block) {
            $title = trim((string) data_get($block, 'data.title'));

            if (blank($title)) {
                continue;
            }

            $id = $this->uniqueAnchorId($title, $usedIds);
            $anchor = [
                'id' => $id,
                'label' => $title,
                'href' => '#'.$id,
            ];

            $blockAnchors[$index] = $anchor;
            $sections[] = $anchor;
        }

        return [
            'sections' => $sections,
            'blockAnchors' => $blockAnchors,
        ];
    }

    /**
     * @param  array<string, int>  $usedIds
     */
    protected function uniqueAnchorId(string $source, array &$usedIds): string
    {
        $baseId = Str::slug($source);

        if (blank($baseId)) {
            $baseId = 'section';
        }

        $usedIds[$baseId] = ($usedIds[$baseId] ?? 0) + 1;

        return $usedIds[$baseId] === 1
            ? $baseId
            : "{$baseId}-{$usedIds[$baseId]}";
    }

    /**
     * Determine the breadcrumb display mode for a given content.
     *
     * - 'child':      has ancestors → show full trail from root to current page
     * - 'standalone': top-level section that does NOT use onepager shell → show own title
     * - 'none':       top-level section with onepager shell, or no section at all
     *
     * @param  list<Content>  $ancestorTrail
     */
    protected function breadcrumbMode(
        Content $content,
        Tenant $tenant,
        array $ancestorTrail,
        ?Content $rootSection,
    ): string {
        if ($ancestorTrail !== []) {
            return 'child';
        }

        if (
            $content->parent_id === null
            && $this->contentResolver->isOnepagerSection($content, $tenant)
            && $rootSection === null
        ) {
            return 'standalone';
        }

        $resolvedPath = $content->resolvedPath();

        if ($resolvedPath !== null && $resolvedPath !== '/') {
            return 'standalone';
        }

        return 'none';
    }
}
