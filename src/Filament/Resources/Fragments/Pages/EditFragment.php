<?php

namespace Mmoollllee\Cms\Filament\Resources\Fragments\Pages;

use Filament\Resources\Pages\EditRecord;
use Mmoollllee\Cms\Filament\Concerns\ManagesDrafts;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;

/**
 * Draft-aware fragment editing ({@see ManagesDrafts}): fragments have no own
 * route, so "Vorschau" opens the homepage in preview mode — the stashed blocks
 * overlay wherever the fragment is embedded.
 */
class EditFragment extends EditRecord
{
    use ManagesDrafts;
    use PastesBuilderBlocks;

    protected static string $resource = FragmentResource::class;
}
