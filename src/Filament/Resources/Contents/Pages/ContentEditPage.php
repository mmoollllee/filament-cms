<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Contracts\ContentBlueprint;
use Mmoollllee\Cms\Contracts\SiteExtension;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Concerns\TransfersBuilderItems;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Sites\SiteExtensionRegistry;

/**
 * Base edit page for every content resource (catch-all AND site-extension types).
 *
 * Provides the pieces every content form needs and that are easy to forget:
 * - the builder clipboard-paste + cross-builder drag & drop Livewire methods
 *   (without them, the builder UI's paste entry / cross-section drop errors),
 * - payload preservation on save (Filament would drop unmanaged payload.* keys),
 * - "manage children" header actions derived from the blueprint hierarchy,
 * - the wide content layout.
 *
 * A site page class only pins its `$resource`:
 *
 *     class EditPage extends ContentEditPage
 *     {
 *         protected static string $resource = Resource::class;
 *     }
 */
abstract class ContentEditPage extends EditRecord
{
    use PastesBuilderBlocks;
    use TransfersBuilderItems;

    protected Width|string|null $maxContentWidth = Width::ScreenTwoExtraLarge;

    /**
     * Preserve payload keys the form does not manage. Filament rehydrates `payload` from
     * only the form's payload fields, so keys like `hero` (on pages whose resource omits
     * the page-header section, e.g. the catch-all default.page) would otherwise be
     * silently dropped on save. Form-managed keys still win.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Content $record */
        $record = $this->getRecord();

        $data['payload'] = ($data['payload'] ?? []) + (is_array($record->payload) ? $record->payload : []);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->buildChildManagementActions(),
            DeleteAction::make(),
        ];
    }

    /**
     * Dynamically builds "manage children" actions based on blueprint metadata.
     *
     * For the current record's content_type, this method iterates over all
     * blueprints registered by the SiteExtension(s) for the tenant's site_key.
     * A child blueprint qualifies if its allowedParentTypes() includes the
     * current record's content_type. For each match, the method resolves
     * which Filament resource manages that child type and creates a link action.
     *
     * The action label uses the child blueprint's pluralLabel(), so different
     * child types managed by the same resource get distinct labels (e.g.
     * "Artikel verwalten" vs "Kategorien verwalten" for a shared resource).
     *
     * @see ContentBlueprint::allowedParentTypes()
     * @see ContentBlueprint::pluralLabel()
     * @see TenantScopedContentResource::getContentTypes()
     *
     * @return array<int, Action>
     */
    protected function buildChildManagementActions(): array
    {
        /** @var Content $record */
        $record = $this->getRecord();
        $tenant = $record->tenant;

        if ($tenant === null) {
            return [];
        }

        $currentType = $record->content_type;
        $siteExtensionRegistry = app(SiteExtensionRegistry::class);
        $extensions = $siteExtensionRegistry->forSite($tenant->site_key);

        // Build a map of content_type → resource class from all extensions.
        $typeToResource = $this->buildTypeToResourceMap($extensions);

        $actions = [];

        foreach ($extensions as $extension) {
            foreach ($extension->blueprints() as $blueprint) {
                if (! in_array($currentType, $blueprint->allowedParentTypes(), true)) {
                    continue;
                }

                $resourceClass = $typeToResource[$blueprint->key()]
                    ?? $this->catchAllResourceFor($blueprint->key());

                if ($resourceClass === null) {
                    continue;
                }

                $actions[] = Action::make('manage-'.Str::kebab($blueprint->key()))
                    ->label($blueprint->pluralLabel().' verwalten')
                    ->icon($resourceClass::getNavigationIcon())
                    ->url($resourceClass::getUrl('index', ['parent' => $record->getKey()]));
            }
        }

        return $actions;
    }

    /**
     * The catch-all content resource, when it manages the given type. Site
     * extensions don't list it (it is registered panel-wide), so child types
     * handled by the catch-all — e.g. pages nested under pages — would
     * otherwise get no "… verwalten" action.
     *
     * @return class-string<TenantScopedContentResource>|null
     */
    protected function catchAllResourceFor(string $contentType): ?string
    {
        $resourceClass = Cms::contentResource();

        if (! is_subclass_of($resourceClass, TenantScopedContentResource::class)) {
            return null;
        }

        return in_array($contentType, $resourceClass::getContentTypes(), true) ? $resourceClass : null;
    }

    /**
     * Builds a lookup map from content_type key to the Filament resource class
     * that manages it.
     *
     * Iterates each extension's resources() and each resource's getContentTypes()
     * to build the reverse index. Only resources extending TenantScopedContentResource
     * are considered.
     *
     * @param  array<int, SiteExtension>  $extensions
     * @return array<string, class-string<TenantScopedContentResource>>
     */
    protected function buildTypeToResourceMap(array $extensions): array
    {
        $map = [];

        foreach ($extensions as $extension) {
            foreach ($extension->resources() as $resourceClass) {
                if (! is_subclass_of($resourceClass, TenantScopedContentResource::class)) {
                    continue;
                }

                foreach ($resourceClass::getContentTypes() as $type) {
                    $map[$type] = $resourceClass;
                }
            }
        }

        return $map;
    }
}
