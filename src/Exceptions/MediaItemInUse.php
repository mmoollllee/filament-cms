<?php

namespace Mmoollllee\Cms\Exceptions;

use Illuminate\Support\Collection;
use Mmoollllee\Cms\Support\Media\References\MediaUsage;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use RuntimeException;

/**
 * A media-library item was about to be deleted while records still use it.
 * Thrown before anything is removed — the file, its derivatives and the item
 * row all stay as they were.
 */
class MediaItemInUse extends RuntimeException
{
    /**
     * @param  array<int, array{item: MediaLibraryItem, usages: Collection<int, MediaUsage>}>  $blocked
     */
    public function __construct(public readonly array $blocked)
    {
        $first = reset($blocked);
        $name = $first === false ? '' : (string) $first['item']->getFirstMedia($first['item']->getMediaLibraryCollectionName())?->file_name;

        parent::__construct(count($blocked) === 1
            ? "Media item [{$name}] is still in use and cannot be deleted."
            : count($blocked).' media items are still in use and cannot be deleted.');
    }
}
