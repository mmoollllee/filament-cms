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
 * the "Unveröffentlicht anlegen" flow ({@see CreatesDrafts}) and the wide content
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
     * "Unveröffentlicht anlegen": the row is created with an empty publishing
     * window and carries the FULL entered state — nothing is live, so no draft
     * stash duplicates it ({@see shouldStashOnDraftCreation()}).
     */
    protected function neutralizeDraftCreationData(array $data): array
    {
        $data['publish_from'] = null;
        $data['publish_until'] = null;

        return $data;
    }

    protected function shouldStashOnDraftCreation(): bool
    {
        return false;
    }

    protected function createDraftActionLabel(): string
    {
        return 'Unveröffentlicht anlegen';
    }

    protected function draftCreatedNotificationTitle(): string
    {
        return 'Unveröffentlicht angelegt';
    }

    protected function draftCreatedNotificationBody(): string
    {
        return 'Gespeichert, aber nicht veröffentlicht — sichtbar über die Vorschau.';
    }

    /**
     * Unpublished creation needs a publishing window, not the draft stash —
     * available even where the model has not adopted HasDraft.
     */
    protected function draftsSupportedForCreation(): bool
    {
        return method_exists(static::getResource()::getModel(), 'isPublished');
    }
}
