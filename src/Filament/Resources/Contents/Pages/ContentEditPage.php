<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Concerns\TransfersBuilderItems;

/**
 * Base edit page for every content resource (catch-all AND site-extension types).
 *
 * Provides the pieces every content form needs and that are easy to forget:
 * - the builder clipboard-paste + cross-builder drag & drop Livewire methods
 *   (without them, the builder UI's paste entry / cross-section drop errors),
 * - payload preservation on save (Filament would drop unmanaged payload.* keys),
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
            DeleteAction::make(),
        ];
    }
}
