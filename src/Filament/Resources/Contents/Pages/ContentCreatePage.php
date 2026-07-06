<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Mmoollllee\Cms\Filament\Concerns\PastesBuilderBlocks;
use Mmoollllee\Cms\Filament\Concerns\TransfersBuilderItems;

/**
 * Base create page for every content resource (catch-all AND site-extension types).
 * Wires the builder's clipboard-paste + cross-builder drag & drop Livewire halves and
 * the wide content layout, so a site page class only pins its `$resource`:
 *
 *     class CreatePage extends ContentCreatePage
 *     {
 *         protected static string $resource = Resource::class;
 *     }
 */
abstract class ContentCreatePage extends CreateRecord
{
    use PastesBuilderBlocks;
    use TransfersBuilderItems;

    protected Width|string|null $maxContentWidth = Width::ScreenTwoExtraLarge;
}
