<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Fields\PublishingFields;
use Mmoollllee\Cms\Filament\Concerns\ManagesDrafts;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Concerns\TransfersBuilderItems;
use Mmoollllee\Cms\Filament\Resources\Contents\TenantScopedContentResource;
use Mmoollllee\Cms\Support\Preview\Drafts;

/**
 * Base edit page for every content resource (catch-all AND site-extension types).
 *
 * Provides the pieces every content form needs and that are easy to forget:
 * - the builder clipboard-paste + cross-builder drag & drop Livewire methods
 *   (without them, the builder UI's paste entry / cross-section drop errors),
 * - payload preservation on save (Filament would drop unmanaged payload.* keys),
 * - the draft workflow ("Entwurf speichern" / "Änderungen anwenden" / Vorschau,
 *   see {@see ManagesDrafts}) with the delete action as footer trash button,
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
    use ManagesDrafts {
        mergeDraftIntoFormData as protected mergeDraftIntoFormDataGeneric;
    }
    use PastesBuilderBlocks;
    use TransfersBuilderItems;

    protected Width|string|null $maxContentWidth = Width::ScreenTwoExtraLarge;

    /**
     * Preserve payload keys the form does not manage. Filament rehydrates `payload` from
     * only the form's payload fields, so keys like `hero` (on pages whose resource omits
     * the page-header section, e.g. the catch-all default.page) would otherwise be
     * silently dropped on save. Form-managed keys still win. Runs for the applied save
     * AND for draft stashes (ManagesDrafts::saveDraft() pipes through this hook).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Content $record */
        $record = $this->getRecord();

        // With the raw payload editor VISIBLE, its folded copy is the whole
        // payload truth — merging the record back in would resurrect keys the
        // user deleted in the editor.
        $hadRawPayloadEditor = array_key_exists('raw_payload', $data);

        $data = TenantScopedContentResource::mergeRawPayload($data);

        if (! $hadRawPayloadEditor) {
            $data['payload'] = ($data['payload'] ?? []) + (is_array($record->payload) ? $record->payload : []);
        }

        return $data;
    }

    /**
     * Content-specific draft fill: the virtual `status` select derives from
     * the publishing window — feed it the DRAFT window, or it would display
     * the applied record's status. Also seeds the raw payload editor's copy
     * from the (live or draft) payload.
     */
    protected function mergeDraftIntoFormData(array $data): array
    {
        $data = $this->mergeDraftIntoFormDataGeneric($data);

        if (
            Drafts::pending($this->getRecord())
            && (array_key_exists('publish_from', $data) || array_key_exists('publish_until', $data))
        ) {
            $data['status'] = PublishingFields::statusForWindow(
                $data['publish_from'] ?? null,
                $data['publish_until'] ?? null,
            );
        }

        $data['raw_payload'] = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        return $data;
    }

    /**
     * The "Vorschau" target: the page's own URL, falling back to the parent
     * (embedded/non-routable types) and finally the homepage.
     */
    protected function previewPath(): string
    {
        /** @var Content $record */
        $record = $this->getRecord();

        return $record->resolvedPath()
            ?? $record->parent?->resolvedPath()
            ?? '/';
    }
}
