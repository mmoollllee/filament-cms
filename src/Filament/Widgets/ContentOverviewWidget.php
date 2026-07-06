<?php

namespace Mmoollllee\Cms\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Mmoollllee\Cms\Cms;

class ContentOverviewWidget extends Widget
{
    protected string $view = 'cms::widgets.content-overview';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $tenantClass = Cms::tenantModel();

        if (! $tenant instanceof $tenantClass) {
            return ['stats' => []];
        }

        $contentQuery = Cms::contentModel()::query()->where('tenant_id', $tenant->id);

        $total = (clone $contentQuery)->count();
        $published = (clone $contentQuery)
            ->whereNotNull('publish_from')
            ->where('publish_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('publish_until')->orWhere('publish_until', '>', now()))
            ->count();
        $draft = $total - $published;

        $byType = (clone $contentQuery)
            ->selectRaw('content_type, count(*) as count')
            ->groupBy('content_type')
            ->pluck('count', 'content_type');

        $resourceMap = $this->buildContentTypeResourceMap();

        $stats = [];

        $catchAllResource = Cms::contentResource();

        $stats[] = [
            'label' => 'Inhalte gesamt',
            'value' => $total,
            'description' => "{$published} veröffentlicht, {$draft} Entwürfe",
            'icon' => 'heroicon-o-document-text',
            'url' => $catchAllResource::getUrl('index'),
        ];

        foreach ($byType as $type => $count) {
            $resourceClass = $resourceMap[$type] ?? null;

            $stats[] = [
                'label' => $this->formatContentType($type),
                'value' => $count,
                'description' => null,
                'icon' => null,
                'url' => $resourceClass ? $resourceClass::getUrl('index') : null,
            ];
        }

        return ['stats' => $stats];
    }

    /**
     * Map each content_type to the content resource that manages it. The base
     * resource class is resolved from Cms::resourceBase() so the widget
     * stays decoupled from any app-specific resource.
     *
     * @return array<string, class-string>
     */
    protected function buildContentTypeResourceMap(): array
    {
        $map = [];

        $base = Cms::resourceBase();
        $panel = Filament::getCurrentPanel();

        if ($panel === null) {
            return $map;
        }

        foreach ($panel->getResources() as $resourceClass) {
            if (! is_subclass_of($resourceClass, $base)) {
                continue;
            }

            foreach ($resourceClass::getContentTypes() as $type) {
                $map[$type] = $resourceClass;
            }
        }

        return $map;
    }

    protected function formatContentType(string $type): string
    {
        $parts = explode('.', $type);

        return ucfirst($parts[count($parts) - 1]);
    }
}
