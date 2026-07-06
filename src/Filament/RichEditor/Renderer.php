<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\RichEditor\TipTapExtensions\CustomBlockExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\DetailsContentExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\DetailsExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\DetailsSummaryExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\ImageExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\LeadExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\MergeTagExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\RawHtmlMergeTagExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\RenderedCustomBlockExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\SmallExtension;
use Mmoollllee\Cms\Tiptap\Marks\HtmlSpan;
use Mmoollllee\Cms\Tiptap\Marks\LinkPicker;
use Mmoollllee\Cms\Tiptap\Nodes\HtmlDiv;
use Tiptap\Core\Extension;
use Tiptap\Extensions\TextAlign;
use Tiptap\Marks\Bold;
use Tiptap\Marks\Code;
use Tiptap\Marks\Highlight;
use Tiptap\Marks\Italic;
use Tiptap\Marks\Strike;
use Tiptap\Marks\Subscript;
use Tiptap\Marks\Superscript;
use Tiptap\Marks\Underline;
use Tiptap\Nodes\Blockquote;
use Tiptap\Nodes\BulletList;
use Tiptap\Nodes\CodeBlock;
use Tiptap\Nodes\Document;
use Tiptap\Nodes\HardBreak;
use Tiptap\Nodes\Heading;
use Tiptap\Nodes\HorizontalRule;
use Tiptap\Nodes\ListItem;
use Tiptap\Nodes\OrderedList;
use Tiptap\Nodes\Paragraph;
use Tiptap\Nodes\Table;
use Tiptap\Nodes\TableCell;
use Tiptap\Nodes\TableHeader;
use Tiptap\Nodes\TableRow;
use Tiptap\Nodes\Text;

class Renderer extends RichContentRenderer
{
    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [
            app(Blockquote::class),
            app(Bold::class),
            app(BulletList::class),
            app(Code::class),
            app(CodeBlock::class),
            app(CustomBlockExtension::class),
            app(DetailsContentExtension::class),
            app(DetailsExtension::class),
            app(DetailsSummaryExtension::class),
            app(Document::class),
            app(HardBreak::class),
            app(Heading::class),
            app(Highlight::class),
            app(HorizontalRule::class),
            app(Italic::class),
            app(ImageExtension::class),
            app(LeadExtension::class),
            app(LinkPicker::class),
            app(ListItem::class),
            app(MergeTagExtension::class),
            app(RawHtmlMergeTagExtension::class),
            app(OrderedList::class),
            app(Paragraph::class),
            app(RenderedCustomBlockExtension::class),
            app(SmallExtension::class),
            app(Strike::class),
            app(Subscript::class),
            app(Superscript::class),
            app(Table::class),
            app(TableCell::class),
            app(TableHeader::class),
            app(TableRow::class),
            app(Text::class),
            app(TextAlign::class, [
                'options' => [
                    'types' => ['heading', 'paragraph'],
                    'alignments' => ['start', 'center', 'end', 'justify'],
                    'defaultAlignment' => 'start',
                ],
            ]),
            app(HtmlDiv::class),
            app(HtmlSpan::class),
            app(Underline::class),
            ...array_reduce(
                $this->getPlugins(),
                fn (array $carry, RichContentPlugin $plugin): array => [
                    ...$carry,
                    ...$plugin->getTipTapPhpExtensions(),
                ],
                initial: [],
            ),
        ];
    }
}
