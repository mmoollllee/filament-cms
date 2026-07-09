<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Sites\ContentBlueprintRegistry;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Base list page for every content resource. The create action carries the
 * `parent` and `type` query parameters through (for parent- / type-scoped
 * lists) and the heading reflects the parent record ("Services: Websites") or
 * the scoped type ("Notes"). A site page class only pins its `$resource`:
 *
 *     class ListPage extends ContentListPage
 *     {
 *         protected static string $resource = Resource::class;
 *     }
 */
abstract class ContentListPage extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                // On a ?type=-scoped list (the listing block's "… verwalten" deep-link)
                // the button reads e.g. "Note anlegen" and creates that very type.
                ->label((static::requestedTypeBlueprint()?->label() ?? static::getResource()::getModelLabel()).' anlegen')
                ->url(function (): string {
                    $resource = static::getResource();
                    $params = [];

                    if (is_subclass_of($resource, TenantScopedContentResource::class)) {
                        $parentId = $resource::getRequestedParentId();

                        if ($parentId !== null) {
                            $params['parent'] = $parentId;
                        }

                        $type = $resource::getRequestedContentType();

                        if ($type !== null) {
                            $params['type'] = $type;
                        }
                    }

                    return $resource::getUrl('create', $params);
                }),
        ];
    }

    public function getHeading(): string
    {
        $resource = static::getResource();

        if (is_subclass_of($resource, TenantScopedContentResource::class)) {
            $parent = $resource::getRequestedParentRecord();

            if ($parent !== null) {
                return "{$resource::getPluralModelLabel()}: {$parent->title}";
            }

            // Scoped via the listing block's "… verwalten" deep-link (?type=): reflect
            // the single type so the multi-type catch-all list reads as that type.
            $label = static::requestedTypeBlueprint()?->pluralLabel();

            if ($label !== null) {
                return $label;
            }
        }

        return parent::getHeading();
    }

    /**
     * The blueprint a ?type= list scope targets (the listing block's "… verwalten"
     * deep-link), or null when the list is not type-scoped. Drives the type-specific
     * heading and create-action label.
     */
    protected static function requestedTypeBlueprint(): ?ContentBlueprint
    {
        $resource = static::getResource();

        if (! is_subclass_of($resource, TenantScopedContentResource::class)) {
            return null;
        }

        $type = $resource::getRequestedContentType();

        if ($type === null) {
            return null;
        }

        $tenant = app(CurrentTenant::class)->get();

        return app(ContentBlueprintRegistry::class)->find($type, $tenant?->site_key);
    }
}
