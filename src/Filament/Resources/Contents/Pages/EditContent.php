<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;

/**
 * Edit page for the catch-all content resource. Bound to the package
 * {@see CatchAllContentResource}; apps register that resource directly (no subclass).
 * Everything generic (payload preservation, drafts, delete action) lives in
 * {@see ContentEditPage}.
 */
class EditContent extends ContentEditPage
{
    protected static string $resource = CatchAllContentResource::class;
}
