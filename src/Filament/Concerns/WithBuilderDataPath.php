<?php

namespace Mmoollllee\Cms\Filament\Concerns;

/**
 * Shared helper for the builder clipboard/transfer concerns: maps a Filament statePath
 * (e.g. "data.blocks.uuid.data.blocks") to a dot-path usable with data_get/data_set on the
 * page's $this->data. Composed into {@see PastesBuilderBlocks} and {@see TransfersBuilderItems}
 * so the rule lives in one place even when a page uses both.
 */
trait WithBuilderDataPath
{
    protected function toDataPath(string $statePath): string
    {
        return str_starts_with($statePath, 'data.')
            ? substr($statePath, 5)
            : $statePath;
    }
}
