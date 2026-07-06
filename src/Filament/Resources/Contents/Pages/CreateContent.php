<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;

/**
 * Create page for the catch-all content resource. Bound to the package
 * {@see CatchAllContentResource}; apps register that resource directly (no subclass).
 */
class CreateContent extends ContentCreatePage
{
    protected static string $resource = CatchAllContentResource::class;
}
