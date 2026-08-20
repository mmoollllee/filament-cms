<?php

namespace Mmoollllee\Cms\Support\Content\Blocks;

use Filament\Forms\Components\Builder\Block;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\Blocks\Contracts\BuilderBlock;

class BuilderBlockRegistry
{
    /** @var array<string, BuilderBlock> */
    protected array $blocks = [];

    /**
     * Memo for {@see rendersInFrontend()} — a block type's frontend view either
     * exists for the whole process or never does.
     *
     * @var array<string, bool>
     */
    protected static array $rendersInFrontend = [];

    /** Drop the view-existence memo (tests swap view paths between cases). */
    public static function flushRenderCache(): void
    {
        static::$rendersInFrontend = [];
    }

    public function register(BuilderBlock $block): void
    {
        $this->blocks[$block->key()] = $block;
    }

    /**
     * @return array<int, Block>
     */
    public function all(?Tenant $tenant): array
    {
        return array_values(array_map(
            fn (BuilderBlock $block): Block => $block->make($tenant),
            $this->blocks,
        ));
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, Block>
     */
    public function only(array $keys, ?Tenant $tenant): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $key): ?Block => isset($this->blocks[$key]) ? $this->blocks[$key]->make($tenant) : null,
                $keys,
            ),
        ));
    }

    /**
     * Whether a block type reaches the site at all.
     *
     * The rule is the existence of its frontend view: a type registered in the
     * builder but without a `blocks::<type>` (or `blocks::<type>.<type>`) view
     * is editor-only, and everything that walks a block tree has to agree on
     * that — the two render loops (`<x-site.content-blocks>` and the section
     * block's child loop) skip it, and the navigation context must not turn it
     * into a jump-navigation anchor for a heading no visitor will ever reach.
     *
     * Static because the callers are Blade views and a request-scoped service,
     * none of which should have to resolve the registry to ask.
     *
     * @see \Mmoollllee\Cms\Support\Content\Blocks\note\NoteBlock — the block this exists for
     */
    public static function rendersInFrontend(?string $type): bool
    {
        if (blank($type)) {
            return false;
        }

        // Memoized: this runs once per block of every rendered page — root
        // blocks, section children and the navigation context all ask — and the
        // view finder re-scans every registered path on a MISS, which is the
        // answer for exactly the block types that ask most often.
        return static::$rendersInFrontend[$type] ??= view()->exists("blocks::{$type}.{$type}")
            || view()->exists("blocks::{$type}");
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, Block>
     */
    public function except(array $keys, ?Tenant $tenant): array
    {
        return array_values(array_filter(
            array_map(
                fn (BuilderBlock $block): ?Block => in_array($block->key(), $keys, true)
                    ? null
                    : $block->make($tenant),
                $this->blocks,
            ),
        ));
    }
}
