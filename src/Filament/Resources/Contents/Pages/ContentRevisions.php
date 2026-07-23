<?php

namespace Mmoollllee\Cms\Filament\Resources\Contents\Pages;

use Mmoollllee\Cms\Filament\Resources\Concerns\ContentRevisionsPage;
use Mmoollllee\Cms\Filament\Resources\Contents\CatchAllContentResource;

/**
 * Revisions page for the catch-all content resource — see
 * {@see ContentRevisionsPage} for the shared behavior.
 */
class ContentRevisions extends ContentRevisionsPage
{
    protected static string $resource = CatchAllContentResource::class;
}
