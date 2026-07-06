<?php

namespace Mmoollllee\Cms\Http\Controllers\Frontend;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View as ViewFacade;
use Mmoollllee\Cms\Support\Content\ContentResolver;
use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;
use Mmoollllee\Cms\Support\Content\NavigationContextBuilder;
use Mmoollllee\Cms\Support\Content\TemplateResolver;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ContentFragmentController
{
    public function __construct(
        protected CurrentTenant $currentTenant,
        protected ContentResolver $contentResolver,
        protected LayoutPresetResolver $layoutPresetResolver,
        protected NavigationContextBuilder $navigationContextBuilder,
        protected TemplateResolver $templateResolver,
    ) {}

    public function __invoke(Request $request): Response
    {
        $tenant = $this->currentTenant->get();
        $path = $request->query('path');
        $presentation = $request->query('presentation');

        abort_if($tenant === null || ! is_string($path), 404);

        $content = $this->contentResolver->findByPath($tenant, $path, $request->user());

        if ($content === null) {
            throw new NotFoundHttpException;
        }

        $navigationContext = $this->navigationContextBuilder->build($tenant, $content);
        $isOnepagerSection = $presentation === 'section'
            && $this->contentResolver->isOnepagerSection($content, $tenant);

        // Teaser mode: swap blocks with teaser_blocks for onepager section rendering.
        if ($isOnepagerSection && filled(data_get($content->payload, 'teaser_blocks'))) {
            $content->setAttribute('blocks', data_get($content->payload, 'teaser_blocks'));
        }

        $this->layoutPresetResolver->preload($content->blocks ?? []);

        $contentView = $this->templateResolver->resolve($content, $tenant);

        $html = ViewFacade::first(
            ["{$tenant->site_key}.{$contentView}", $contentView],
            [
                'tenant' => $tenant,
                'content' => $content,
                'navigationContext' => $navigationContext,
            ],
        )->render();

        return response($html)
            ->header('X-Fragment-Navigation', json_encode($navigationContext))
            ->header('X-Fragment-Title', $tenant->frontendTitleFor($content))
            ->header('X-Fragment-Content-Type', $content->content_type)
            ->header('X-Fragment-Layout-Preset', $content->resolvedLayoutPreset());
    }
}
