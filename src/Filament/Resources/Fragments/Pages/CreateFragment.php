<?php

namespace Mmoollllee\Cms\Filament\Resources\Fragments\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;

class CreateFragment extends CreateRecord
{
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
}
