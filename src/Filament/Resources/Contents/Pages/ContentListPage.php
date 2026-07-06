<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;

/**
 * Base list page for every content resource. The create action carries the
 * `parent` query parameter through (for parent-scoped types) and the heading
 * reflects the parent record ("Services: Websites"). A site page class only
 * pins its `$resource`:
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
                ->label(static::getResource()::getModelLabel().' anlegen')
                ->url(function (): string {
                    $resource = static::getResource();
                    $params = [];

                    if (is_subclass_of($resource, TenantScopedContentResource::class)) {
                        $parentId = $resource::getRequestedParentId();

                        if ($parentId !== null) {
                            $params['parent'] = $parentId;
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
        }

        return parent::getHeading();
    }
}
