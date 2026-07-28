<?php

namespace Mmoollllee\Cms\Filament\Widgets\Concerns;

use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Contracts\Fragment;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;
use Mmoollllee\Cms\Filament\Widgets\PendingContentWidget;
use Mmoollllee\Cms\Filament\Widgets\RecentVersionsWidget;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Content\ContentResourceLocator;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Deep links from a dashboard table row to the resource that manages the record,
 * plus the record's human type label.
 *
 * Shared by the widgets that list records they did not query through a resource
 * ({@see RecentVersionsWidget},
 * {@see PendingContentWidget}).
 *
 * Everything is memoized per request: Filament evaluates url() and visible()
 * once per action per row, and each resolution otherwise walks the site-extension
 * registry and the route generator again.
 */
trait ResolvesContentResourceUrls
{
    /** @var array<string, string|null> */
    private array $resourceUrlMemo = [];

    /** @var array<string, class-string|null> */
    private array $resourceClassMemo = [];

    /** @var array<string, string> */
    private array $typeLabelMemo = [];

    /**
     * URL to the managing resource's page for the record — the type-specific
     * site resource wins over the catch-all (ContentResourceLocator), and
     * inaccessible resources yield no link (mirrors the listing block's
     * canAccess() guard: a visible button must not lead into a 403).
     */
    protected function resourceUrlForRecord(?Model $record, string $page): ?string
    {
        if ($record === null) {
            return null;
        }

        $key = $record::class.':'.$record->getKey().':'.$page;

        if (! array_key_exists($key, $this->resourceUrlMemo)) {
            $this->resourceUrlMemo[$key] = $this->buildResourceUrl($record, $page);
        }

        return $this->resourceUrlMemo[$key];
    }

    protected function buildResourceUrl(Model $record, string $page): ?string
    {
        $resource = $this->resourceForRecord($record);

        if ($resource === null || ! $resource::hasPage($page) || ! $resource::canAccess()) {
            return null;
        }

        return $resource::getUrl($page, ['record' => $record]);
    }

    /** @return class-string|null */
    protected function resourceForRecord(Model $record): ?string
    {
        if ($record instanceof Fragment) {
            return FragmentResource::class;
        }

        $type = (string) $record->content_type;

        // array_key_exists, not ??= — a null result (unmanaged type) must be
        // memoized too, or every row retries the locator scan.
        if (! array_key_exists($type, $this->resourceClassMemo)) {
            $this->resourceClassMemo[$type] = app(ContentResourceLocator::class)->resolve(
                $type,
                app(CurrentTenant::class)->get(),
            );
        }

        return $this->resourceClassMemo[$type];
    }

    /** Blueprint label for contents, "Fragment" for fragments. */
    protected function typeLabelForRecord(?Model $record): ?string
    {
        if ($record === null) {
            return null;
        }

        if ($record instanceof Fragment) {
            return FragmentResource::getModelLabel();
        }

        return $this->typeLabelMemo[(string) $record->content_type] ??= app(ContentBlueprintRegistry::class)->labelFor(
            (string) $record->content_type,
            app(CurrentTenant::class)->get()?->site_key,
        );
    }
}
