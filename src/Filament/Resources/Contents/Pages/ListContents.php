<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;

/**
 * List page for the catch-all content resource. Bound to the package
 * {@see CatchAllContentResource}; apps register that resource directly (no subclass).
 */
class ListContents extends ContentListPage
{
    protected static string $resource = CatchAllContentResource::class;
}
