<?php

namespace Mmoollllee\Cms\Filament\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;

/**
 * Factory for the engine's block builders (page blocks, teaser blocks, fragment
 * blocks, section children). All builders share the same UX: icon headers, the
 * per-item "Block-Optionen" + "Block kopieren" actions, the block picker's
 * "Aus Zwischenablage" entry, ⌘C / ⌘V on a selected block, German add-labels and —
 * in preview mode — inline preview editing instead of Filament's edit modal.
 *
 * Copy, paste and dragging blocks between builders come from
 * mmoollllee/filament-builder-extensions; the package's view overrides add what is the
 * CMS's own (inline preview editing, inactive blocks, option badges) and offer the paste
 * in the block picker.
 *
 * Centralized so a UX improvement lands in every builder of every project at once.
 */
class BlockBuilder
{
    /**
     * @param  array<array-key, Block|array<Block>>  $blocks  flat, grouped by the blocks' own groups ({@see grouped()}), or as Filament's named groups
     * @param  bool  $previews  render items as clickable previews (block cards) instead of open forms
     * @param  string|null  $sortableGroup  builders sharing a group name allow drag & drop between each other (the package's transferGroup())
     * @param  array<int, Action|Closure>  $extraItemActions  appended after the shared options/copy actions; a Closure returning the action gives every builder one of its own (see below)
     */
    public static function make(
        string $statePath,
        ?Tenant $tenant,
        array $blocks,
        bool $previews = true,
        ?string $sortableGroup = null,
        array $extraItemActions = [],
    ): Builder {
        $builder = BlockBuilderField::make($statePath)
            // Since Filament 5.7 the Builder renders PHP-side (toEmbeddedHtml);
            // an explicit view re-enters the classic path, where the package's
            // prependNamespace override (inline preview editing, inactive-block
            // UI, option badges, the picker's paste entry) wins the lookup. Scoped
            // HERE — not via a global configureUsing — so builders outside the CMS
            // keep vendor rendering.
            ->view('filament-forms::components.builder')
            ->hiddenLabel()
            ->blockIcons()
            ->blockNumbers(false)
            // Closures, so every builder gets actions of its own: Filament clones a section's
            // child builder per section but shares the action objects it was given, and an
            // object shared by those clones ends up bound to whichever cached it last — a
            // paste or copy in one section would act on another.
            ->extraItemActions([
                fn (): Action => BaseBuilderBlock::blockOptionsAction($tenant),
                fn (): Action => BaseBuilderBlock::copyBlockAction(),
                ...$extraItemActions,
            ])
            // No button of its own: the overridden block picker offers it.
            ->registerActions([
                fn (): Action => BaseBuilderBlock::pasteBlockAction(),
            ])
            // ⌘C / ⌘V on a block a click selected (outside its fields): the two actions above.
            ->clipboardShortcuts()
            ->addActionLabel('Block hinzufügen')
            ->blocks(static::grouped($blocks));

        if ($previews) {
            $builder
                ->blockPreviews()
                // The overridden builder view swaps the preview for the item's inline
                // form on click ("inline editing"), so Filament's edit-modal action is
                // redundant — hidden on every preview builder while that view renders.
                ->editAction(fn (Action $action) => $action->hidden(static::rendersCmsView()))
                ->addAction(fn (Action $action) => $action
                    ->modalHeading(fn (array $arguments, Builder $component): string => static::blockCreateHeading($arguments, $component))
                )
                ->addBetweenAction(fn (Action $action) => $action
                    ->modalHeading(fn (array $arguments, Builder $component): string => static::blockCreateHeading($arguments, $component))
                );
        }

        if ($sortableGroup !== null) {
            $builder->transferGroup($sortableGroup);
        }

        return $builder;
    }

    /**
     * The blocks as Filament's block picker groups them — named arrays, each under the
     * heading its block class names ({@see BaseBuilderBlock::group()}), like the categories of
     * Gutenberg's inserter: ungrouped blocks first, then each group in the order its first
     * block comes. Blocks handed in as groups already, or none naming a group, stay as they are.
     *
     * @param  array<array-key, Block|array<Block>>  $blocks
     * @return array<array-key, Block|array<Block>>
     */
    public static function grouped(array $blocks): array
    {
        if (array_filter($blocks, fn (mixed $block): bool => ! $block instanceof Block) !== []) {
            return $blocks;
        }

        $registry = app(BuilderBlockRegistry::class);
        $ungrouped = [];
        $groups = [];

        foreach ($blocks as $block) {
            if (filled($group = $registry->groupOf($block->getName()))) {
                $groups[$group][] = $block;
            } else {
                $ungrouped[] = $block;
            }
        }

        return ($groups === []) ? $blocks : [...$ungrouped, ...$groups];
    }

    /**
     * Whether the CMS's builder view renders: it asks Filament's own protected helpers for
     * the markup Filament makes. Should an update rename one, the builders render as
     * Filament renders them (see builder.blade.php) — previews then open Filament's edit
     * modal instead of the inline form, which is why make() hides that action only here.
     */
    public static function rendersCmsView(): bool
    {
        return method_exists(Builder::class, 'getAlpineAttributes')
            && method_exists(Builder::class, 'getReorderingActionAttributes')
            && method_exists(Builder::class, 'getReorderingDescriptionHtml');
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
