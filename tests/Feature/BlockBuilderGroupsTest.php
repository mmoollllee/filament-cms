<?php

/*
 * BlockBuilder::grouped(): the block classes' own groups (BaseBuilderBlock::group(), what a
 * block's category is in Gutenberg's inserter) become the named arrays Filament's block
 * picker heads its blocks with. The picker side is PanelBuilderRenderTest's.
 */

use Filament\Forms\Components\Builder\Block;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Forms\BlockBuilder;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;

/** Registers a block `$key` in group `$group`; returns its Filament block. */
function groupedTestBlock(string $key, ?string $group): Block
{
    app(BuilderBlockRegistry::class)->register(new class($key, $group) extends BaseBuilderBlock
    {
        public function __construct(private string $blockKey, private ?string $heading) {}

        public function key(): string
        {
            return $this->blockKey;
        }

        public function make(?Tenant $tenant): Block
        {
            return Block::make($this->blockKey);
        }

        public function group(): ?string
        {
            return $this->heading;
        }
    });

    return Block::make($key);
}

/**
 * The block names of `$blocks`, as the picker reads them: a group's under its name.
 *
 * @return array<array-key, string|list<string>>
 */
function groupedTestNames(array $blocks): array
{
    return array_map(
        fn (Block|array $entry): string|array => is_array($entry) ? array_map(fn (Block $block): string => $block->getName(), $entry) : $entry->getName(),
        $blocks,
    );
}

it('puts the ungrouped blocks first, then each group where its first block comes', function () {
    $blocks = [
        groupedTestBlock('lead', null),
        groupedTestBlock('gallery', 'Medien'),
        groupedTestBlock('quote', null),
        groupedTestBlock('columns', 'Layout'),
        groupedTestBlock('video', 'Medien'),
    ];

    expect(groupedTestNames(BlockBuilder::grouped($blocks)))->toBe([
        0 => 'lead',
        1 => 'quote',
        'Medien' => ['gallery', 'video'],
        'Layout' => ['columns'],
    ]);
});

it('leaves blocks as they are when none names a group, or when they come grouped already', function () {
    $flat = [groupedTestBlock('lead', null), groupedTestBlock('quote', null)];
    $named = ['Eigene' => [groupedTestBlock('gallery', 'Medien')], groupedTestBlock('lead', null)];

    expect(BlockBuilder::grouped($flat))->toBe($flat)
        ->and(BlockBuilder::grouped($named))->toBe($named);
});

it('leaves a block without a registry entry ungrouped', function () {
    $blocks = [Block::make('unregistered'), groupedTestBlock('gallery', 'Medien')];

    expect(groupedTestNames(BlockBuilder::grouped($blocks)))->toBe([0 => 'unregistered', 'Medien' => ['gallery']]);
});
