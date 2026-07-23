<?php

namespace Mmoollllee\Cms\Filament\Resources\Fragments\Pages;

use Mmoollllee\Cms\Filament\Resources\Concerns\ContentRevisionsPage;
use Mmoollllee\Cms\Filament\Resources\Fragments\FragmentResource;

/**
 * Revisions page for fragments — see {@see ContentRevisionsPage}.
 */
class FragmentRevisions extends ContentRevisionsPage
{
    protected static string $resource = FragmentResource::class;
}
