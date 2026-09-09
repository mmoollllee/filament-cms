<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use Mmoollllee\Cms\Filament\Concerns\CreatesDrafts;
use Mmoollllee\Cms\Filament\Concerns\LocksRecords;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Concerns\TransfersBuilderItems;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Support\FormFieldValidation;

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
     * The edit page inherits the same wrapper from {@see LocksRecords}; a create page
     * holds no lock, so it needs its own. Without it a guard that throws inside the MODEL
     * — the path collision check runs for every writer, not only for the form — leaves
     * its message under a key the form does not own, and "Erstellen" appears to do
     * nothing at all ({@see FormFieldValidation}).
     */
    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (ValidationException $exception) {
            throw FormFieldValidation::rekey($exception, FormFieldValidation::statePathOf($this));
        }
    }

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
