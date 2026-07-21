<?php

namespace Mmoollllee\Cms\Filament\Resources\Fragments\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Mmoollllee\Cms\Filament\Concerns\CreatesDrafts;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;

class CreateFragment extends CreateRecord
{
    use CreatesDrafts;
    use PastesBuilderBlocks;

    protected static string $resource = FragmentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->getKey();

        return $data;
    }

    /**
     * Creating as draft: no active blocks in the applied row — hasContent()
     * stays false, so the fragment renders nowhere until the draft is applied
     * (the branding-tenant cascade keeps serving its fallback meanwhile).
     */
    protected function neutralizeDraftCreationData(array $data): array
    {
        $data['blocks'] = [];

        return $data;
    }
}
