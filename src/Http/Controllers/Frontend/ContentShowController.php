<?php

namespace Mmoollllee\Cms\Http\Controllers\Frontend;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as ViewFacade;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;
use Mmoollllee\Cms\Support\Content\NavigationContextBuilder;
use Mmoollllee\Cms\Support\Content\TemplateResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Main frontend controller — the single entry point for all public page requests.
 *
 * Handles the catch-all route `/{path?}`. Resolution flow:
 * 1. Reads the current tenant (set by the host-resolution middleware)
 * 2. Looks up the Content record via ContentResolver::findByPath()
 * 3. If the content is an onepager shell root → delegates to OnepagerShellController
 * 4. Otherwise → renders the standard content show layout with navigation context
 *
 * @see ContentResolver::findByPath() — path → Content lookup
 * @see TemplateResolver::resolve() — Content → Blade view
 * @see OnepagerShellController — onepager delegation
 */
class ContentShowController
{
    public function __construct(
        protected CurrentTenant $currentTenant,
        protected ContentResolver $contentResolver,
        protected NavigationContextBuilder $navigationContextBuilder,
        protected TemplateResolver $templateResolver,
        protected LayoutPresetResolver $layoutPresetResolver,
        protected OnepagerShellController $onepagerShellController,
        protected ContentBlueprintRegistry $blueprints,
    ) {}

    /**
     * Resolve the "back" button for the standalone page layout.
     *
     * A content type contributes its own target via {@see ContentBlueprint::backButton()}
     * (e.g. an article detail linking back to "/blog"); otherwise this falls back to a generic
     * default (up to the parent, else the homepage). No app-specific content types are baked
     * into the package here — projects declare theirs on their blueprints.
     *
     * @return array{href: string, label: string}|null
     */
    protected function resolveBackButton(Content $content): ?array
    {
        if ($content->resolvedPath() === '/') {
            return null;
        }

        $custom = $this->blueprints
            ->find($content->content_type, $this->currentTenant->get()?->site_key)
            ?->backButton($content);

        if ($custom !== null) {
            return $custom;
        }

        if ($content->parent !== null) {
            return [
                'href' => $content->parent->resolvedPath(),
                'label' => __('cms::frontend.back_to_parent'),
            ];
        }

        return [
            'href' => '/',
            'label' => __('cms::frontend.back_to_home'),
        ];
    }

    /**
     * Handle the incoming page request.
     *
     * The route parameter `$path` is null for the homepage ("/").
     */
    public function __invoke(Request $request, ?string $path = null): View
    {
        $tenant = $this->currentTenant->get();

        abort_if($tenant === null, 404);

        $content = $this->contentResolver->findByPath($tenant, $path, $request->user());

        if ($content === null) {
            throw new NotFoundHttpException;
        }

        $onepagerSection = $this->contentResolver->onepagerSectionFor($content, $tenant);

        if ($onepagerSection !== null && $onepagerSection->is($content)) {
            return $this->onepagerShellController->render($request, $tenant, $content);
        }

        $this->layoutPresetResolver->preload($content->blocks ?? []);

        $socialLinks = $tenant->resolvedSocialLinksForDisplay();
        $navigationContext = $this->navigationContextBuilder->build($tenant, $content);
        $initialBreadcrumbs = $this->navigationContextBuilder->showsBreadcrumbs($navigationContext)
            ? $navigationContext['breadcrumbs']
            : [];

        return ViewFacade::first(
            [
                "{$tenant->site_key}.frontend.standalone",
                'frontend.standalone',
            ],
            [
                'tenant' => $tenant,
                'content' => $content,
                'contentView' => $this->templateResolver->resolve($content, $tenant),
                'sectionLinks' => Menu::linksForLocation('header', $tenant),
                'legalLinks' => Menu::linksForLocation('footer', $tenant),
                'socialLinks' => $socialLinks,
                'navigationContext' => $navigationContext,
                'initialBreadcrumbs' => $initialBreadcrumbs,
                'backButton' => $this->resolveBackButton($content),
            ],
        );
    }
}
