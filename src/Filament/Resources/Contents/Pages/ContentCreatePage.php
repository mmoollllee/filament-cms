<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Mmoollllee\Cms\Filament\Concerns\CreatesDrafts;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Concerns\TransfersBuilderItems;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;

/**
 * Base create page for every content resource (catch-all AND site-extension types).
 * Wires the builder's clipboard-paste + cross-builder drag & drop Livewire halves,
 * the "Als Entwurf anlegen" flow ({@see CreatesDrafts}) and the wide content
 * layout, so a site page class only pins its `$resource`:
 *
 *     class CreatePage extends ContentCreatePage
 *     {
 *         protected static string $resource = Resource::class;
 *     }
 */
abstract class ContentCreatePage extends CreateRecord
{
    use CreatesDrafts;
    use PastesBuilderBlocks;
    use TransfersBuilderItems;

    protected Width|string|null $maxContentWidth = Width::ScreenTwoExtraLarge;

    /**
     * Fold the raw payload editor's copy (`raw_payload`) into `payload` — see
     * {@see TenantScopedContentResource::mergeRawPayload()}.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return TenantScopedContentResource::mergeRawPayload($data);
    }

    /**
     * Creating as draft: the applied row goes live UNPUBLISHED — the entered
     * publishing window only takes effect once the draft is applied.
     */
    protected function neutralizeDraftCreationData(array $data): array
    {
        $data['publish_from'] = null;
        $data['publish_until'] = null;

        return $data;
    }
}
