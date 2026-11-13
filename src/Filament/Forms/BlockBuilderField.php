<?php

namespace Mmoollllee\Cms\Filament\Forms;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Schemas\Schema;
use Mmoollllee\Cms\Cms;

/**
 * The builder field {@see BlockBuilder::make()} builds: Filament's, which with lazy block forms
 * ({@see Cms::enableLazyBlockForms()}) loads the form behind each preview only once it is
 * edited.
 *
 * Lazy is set on each item's own schema, in every request — the request that loads one asks
 * this same method —, never on the block. A block keeps the schema it was made with, so
 * Filament's add modal (which takes a block's components as an array) and a block schema given
 * as a closure (evaluated per item, inside the form) work as they do without the opt-in.
 */
class BlockBuilderField extends Builder
{
    /**
     * Filament's item schemas, the form behind a preview (inline preview editing) made a lazy
     * one: a placeholder until a click on the preview shows it, or until it holds a
     * validation error.
     *
     * @return array<Schema>
     */
    public function getDefaultChildSchemas(): array
    {
        $items = parent::getDefaultChildSchemas();

        if ((! Cms::hasLazyBlockForms()) || (! $this->hasBlockPreviews()) || $this->hasInteractiveBlockPreviews()) {
            return $items;
        }

        foreach ($items as $item) {
            $block = $item->getParentComponent();

            if (($block instanceof Block) && $block->hasPreview()) {
                $item->lazyLoaded();
            }
        }

        return $items;
    }
}
