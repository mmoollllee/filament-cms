<?php

namespace Mmoollllee\Cms\Support\Content\Blocks;

use Filament\Forms\Components\Builder\Block;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Content\Blocks\Contracts\BuilderBlock;

class BuilderBlockRegistry
{
    /** @var array<string, BuilderBlock> */
    protected array $blocks = [];

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
