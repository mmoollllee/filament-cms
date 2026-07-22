<?php

namespace Mmoollllee\Cms\Filament\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;

/**
 * Factory for the engine's block builders (page blocks, teaser blocks, fragment
 * blocks, section children). All builders share the same UX: icon headers, the
 * per-item "Block-Optionen" + "Block kopieren" actions, German add-labels and —
 * in preview mode — inline preview editing instead of Filament's edit modal.
 *
 * Centralized so a UX improvement lands in every builder of every project at once.
 */
class BlockBuilder
{
    /**
     * @param  array<int, Block>  $blocks
     * @param  bool  $previews  render items as clickable previews (block cards) instead of open forms
     * @param  string|null  $sortableGroup  builders sharing a group name allow drag & drop between each other
     * @param  array<int, Action>  $extraItemActions  appended after the shared options/copy actions
     */
    public static function make(
        string $statePath,
        ?Tenant $tenant,
        array $blocks,
        bool $previews = true,
        ?string $sortableGroup = null,
        array $extraItemActions = [],
    ): Builder {
        $builder = Builder::make($statePath)
            // Since Filament 5.7 the Builder renders PHP-side (toEmbeddedHtml);
            // an explicit view re-enters the classic path, where the package's
            // prependNamespace override (cross-builder DnD, inline preview
            // editing, inactive-block UI, clipboard paste) wins the lookup.
            // Scoped HERE — not via a global configureUsing — so builders
            // outside the CMS keep vendor rendering and don't grow a paste
            // entry whose pasteBuilderBlock() method their page lacks.
            ->view('filament-forms::components.builder')
            ->hiddenLabel()
            ->blockIcons()
            ->blockNumbers(false)
            ->extraItemActions([
                BaseBuilderBlock::blockOptionsAction($tenant),
                BaseBuilderBlock::copyBlockAction(),
                ...$extraItemActions,
            ])
            ->addActionLabel('Block hinzufügen')
            ->blocks($blocks);

        if ($previews) {
            $builder
                ->blockPreviews()
                // The overridden builder view swaps the preview for the item's inline
                // form on click ("inline editing"), so Filament's edit-modal action is
                // redundant — hide it on every preview builder.
                ->editAction(fn (Action $action) => $action->hidden())
                ->addAction(fn (Action $action) => $action
                    ->modalHeading(fn (array $arguments, Builder $component): string => static::blockCreateHeading($arguments, $component))
                )
                ->addBetweenAction(fn (Action $action) => $action
                    ->modalHeading(fn (array $arguments, Builder $component): string => static::blockCreateHeading($arguments, $component))
                );
        }

        if ($sortableGroup !== null) {
            $builder->extraAttributes(['data-sortable-group' => $sortableGroup]);
        }

        return $builder;
    }

    /**
     * The "<Block> erstellen" modal heading, shared by the add + add-between actions.
     *
     * @param  array{block?: string}  $arguments
     */
    protected static function blockCreateHeading(array $arguments, Builder $component): string
    {
        return ($component->getBlock($arguments['block'])?->getLabel() ?? $arguments['block']).' erstellen';
    }
}
