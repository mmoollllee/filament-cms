<?php

namespace Mmoollllee\Cms\Filament\Widgets;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Enums\ContentStatus;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;

/**
 * Content counts for the current tenant, one card per managing resource.
 *
 * Grouping is by RESOURCE, not by content_type: the catch-all resource owns
 * several types (default.page + default.section), so a per-type grid would show
 * two cards both labelled "Seiten" and both linking to the same list. Grouping
 * by resource makes the dashboard mirror the sidebar exactly — same labels,
 * same icons, same targets — which is the whole point of the widget.
 *
 * Labels come from the resource ({@see TenantScopedContentResource::getPluralModelLabel()}),
 * never from the content_type slug. Types no resource claims fall back to their
 * blueprint's singular label and render without a link.
 */
class ContentOverviewWidget extends Widget
{
    protected string $view = 'cms::widgets.content-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 20;

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $tenantClass = Cms::tenantModel();

        if (! $tenant instanceof $tenantClass) {
            return ['stats' => [], 'summary' => null];
        }

        $contentQuery = fn (): Builder => Cms::contentModel()::query()->where('tenant_id', $tenant->id);

        $countsByType = $contentQuery()
            ->selectRaw('content_type, count(*) as count')
            ->groupBy('content_type')
            ->pluck('count', 'content_type')
            ->all();

        return [
            'stats' => $this->buildStats($countsByType, $tenant->site_key),
            'summary' => $this->buildSummary($contentQuery, (int) array_sum($countsByType)),
        ];
    }

    /**
     * One card per managing resource (counts of its types summed), followed by
     * any unmanaged types.
     *
     * @param  array<string, int>  $countsByType
     * @return list<array{label: string, value: int, icon: string|BackedEnum|null, url: string|null}>
     */
    protected function buildStats(array $countsByType, ?string $siteKey): array
    {
        $resourceMap = $this->buildContentTypeResourceMap();

        $byResource = [];
        $unmanaged = [];

        foreach ($countsByType as $type => $count) {
            $resourceClass = $resourceMap[$type] ?? null;

            if ($resourceClass === null) {
                $unmanaged[$type] = (int) $count;

                continue;
            }

            $byResource[$resourceClass] = ($byResource[$resourceClass] ?? 0) + (int) $count;
        }

        $stats = [];

        foreach ($byResource as $resourceClass => $count) {
            $stats[] = [
                'label' => $resourceClass::getPluralModelLabel(),
                'value' => $count,
                'icon' => $resourceClass::getNavigationIcon(),
                'url' => $resourceClass::getUrl('index'),
            ];
        }

        $registry = app(ContentBlueprintRegistry::class);

        foreach ($unmanaged as $type => $count) {
            $stats[] = [
                'label' => $registry->labelFor($type, $siteKey),
                'value' => $count,
                'icon' => null,
                'url' => null,
            ];
        }

        return $stats;
    }

    /**
     * The one-line status recap above the grid, e.g.
     * "114 Inhalte · 113 veröffentlicht · 1 unveröffentlicht".
     *
     * Only non-zero states are listed, and the wording follows
     * {@see ContentStatus::options()} — in particular the never-published state
     * is "unveröffentlicht", NOT "Entwurf". "Entwurf" belongs exclusively to the
     * unapplied draft stash ({@see PendingContentWidget}); the previous
     * "N Entwürfe" label here was exactly the confusion ContentStatus warns
     * about (and it counted scheduled + expired records too).
     *
     * @param  callable(): Builder  $query
     */
    protected function buildSummary(callable $query, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }

        $counts = [
            ContentStatus::Published->value => $query()->published()->count(),
            ContentStatus::Scheduled->value => $query()->scheduled()->count(),
            ContentStatus::Expired->value => $query()->expired()->count(),
            ContentStatus::Draft->value => $query()->unpublished()->count(),
        ];

        $labels = ContentStatus::options();

        $parts = [$total.' '.($total === 1 ? 'Inhalt' : 'Inhalte')];

        foreach ($counts as $status => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.mb_strtolower($labels[$status]);
            }
        }

        return implode(' · ', $parts);
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

            // A visible card must not lead into a 403 — same guard the recent
            // versions widget applies to its row actions.
            if (! $resourceClass::canAccess()) {
                continue;
            }

            foreach ($resourceClass::getContentTypes() as $type) {
                $map[$type] = $resourceClass;
            }
        }

        return $map;
    }
}
