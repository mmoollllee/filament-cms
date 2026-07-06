<?php

namespace Mmoollllee\Cms\Http\Controllers\Frontend;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as ViewFacade;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;
use Mmoollllee\Cms\Support\Content\NavigationContextBuilder;
use Mmoollllee\Cms\Support\Content\TemplateResolver;

/**
 * Renders the onepager shell — a layout that lazy-loads sections via AJAX.
 *
 * Called by ContentShowController when the matched content is an onepager
 * shell root (e.g. the homepage "/" or a section landing page "/services").
 *
 * The shell pre-renders the current section's HTML and provides a JSON payload
 * describing all sections (path, anchor, navigation context). The Alpine.js
 * `siteOnepager` component uses this data to fetch additional sections on scroll
 * via the `content.fragment` route.
 *
 * @see ContentShowController — caller
 * @see ContentFragmentController — AJAX endpoint
 */
class OnepagerShellController
{
    public function __construct(
        protected ContentResolver $contentResolver,
        protected NavigationContextBuilder $navigationContextBuilder,
        protected TemplateResolver $templateResolver,
        protected LayoutPresetResolver $layoutPresetResolver,
    ) {}

    public function render(
        Request $request,
        Tenant $tenant,
        Content $currentContent,
    ): View {
        $sections = $this->contentResolver->sections($tenant, $request->user());

        // Preload all LayoutPreset IDs from all sections in a single query.
        foreach ($sections as $section) {
            $this->layoutPresetResolver->preload($section->blocks ?? []);
        }

        $socialLinks = $tenant->resolvedSocialLinksForDisplay();
        $sectionsPayload = $sections
            ->map(fn (Content $section): array => $this->sectionPayload($section, $tenant))
            ->values();
        $sectionNavigationContexts = $sectionsPayload
            ->mapWithKeys(fn (array $section): array => [
                $section['path'] => $section['navigation'],
            ])
            ->all();
        $currentNavigationContext = $sectionNavigationContexts[$currentContent->resolvedPath()] ?? $this->navigationContextBuilder->build($tenant, $currentContent);

        return ViewFacade::first(
            [
                "{$tenant->site_key}.frontend.onepager",
                'frontend.onepager',
            ],
            [
                'tenant' => $tenant,
                'content' => $currentContent,
                'currentContent' => $currentContent,
                'currentContentView' => $this->templateResolver->resolve($currentContent, $tenant),
                'sectionsPayload' => $sectionsPayload->all(),
                'sectionLinks' => Menu::linksForLocation('header', $tenant),
                'legalLinks' => Menu::linksForLocation('footer', $tenant),
                'socialLinks' => $socialLinks,
                'contentEndpoint' => route('content.fragment'),
                'initialNavigationContext' => $currentNavigationContext,
                'initialBreadcrumbs' => [],
            ],
        );
    }

    /**
     * Build the payload for a single onepager section.
     *
     * This data is JSON-serialized as `sectionsPayload` in the shell view and
     * consumed by the Alpine.js siteOnepager component to:
     * - determine scroll-to anchors and section order
     * - update the navigation context (breadcrumbs, indicator) on scroll
     * - fetch section HTML fragments lazily
     *
     * @return array{content: Content, path: string, anchor: string|null, navigation: array<string, mixed>, title: string, label: string}
     */
    protected function sectionPayload(Content $section, Tenant $tenant): array
    {
        $path = (string) $section->resolvedPath();

        return [
            'content' => $section,
            'path' => $path,
            'anchor' => $this->contentResolver->onepagerAnchor($section, $tenant),
            'navigation' => $this->navigationContextBuilder->indicatorContext($tenant, $section),
            'title' => $tenant->frontendTitleFor($section),
            'label' => $section->title,
        ];
    }
}
