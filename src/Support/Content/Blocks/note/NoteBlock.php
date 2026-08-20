<?php

namespace Mmoollllee\Cms\Support\Content\Blocks\note;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\RichEditor;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\ButtonGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\NavigationCardGroupBlock;
use Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock;
use Mmoollllee\Cms\Support\Content\Blocks\BuilderBlockRegistry;

/**
 * Editorial note: rich text that only ever exists inside the panel.
 *
 * Two jobs, both of them about the builder rather than the page. It stands in
 * for content a template renders on its own — a section whose composition lives
 * in Blade (the services slider, a downloads filter) otherwise shows an empty
 * builder, which reads as "nothing here" rather than "edited elsewhere". And it
 * carries an ordinary note between editors, with the RichEditor's link and
 * button blocks available to point at wherever the real records are managed.
 *
 * It never renders on the site, and the mechanism is the absence of a
 * `blocks::note` view rather than a flag: {@see BuilderBlockRegistry::rendersInFrontend()}
 * treats a block type without a frontend view as non-existent, so the block
 * loops skip it and it never becomes a jump-navigation anchor.
 *
 * The editor is a plain RichEditor, not {@see BaseBuilderBlock::richEditorWithSource()}:
 * that pairing exists to give a block an HTML source tab, and the two state
 * keys it needs are only worth it for markup that ends up on the page. Nothing
 * here does, so there is no HTML to hand-tune.
 */
class NoteBlock extends BaseBuilderBlock
{
    public function key(): string
    {
        return 'note';
    }

    public function make(?Tenant $tenant): Block
    {
        return Block::make('note')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->label('Notiz')
            ->title('title', placeholder: 'Titel', suffix: 'Notiz')
            // Rendered as a card in the builder; clicking it opens this schema
            // inline (see the package's builder override).
            ->preview('blocks::note.preview')
            ->schema([
                ...static::optionHiddenFields(),
                RichEditor::make('content')
                    ->hiddenLabel()
                    ->customBlocks([
                        ButtonGroupBlock::class,
                        NavigationCardGroupBlock::class,
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
