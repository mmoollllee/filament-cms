<?php

namespace Mmoollllee\Cms\Support\Content;

use Mmoollllee\Cms\Filament\RichEditor\Blocks\ButtonGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\Blocks\NavigationCardGroupBlock;
use Mmoollllee\Cms\Filament\RichEditor\Renderer;
use Mmoollllee\Cms\Support\Shortcodes;
use Mmoollllee\Cms\Support\SpamprotectHtml;

/**
 * Frontend helper for rendering RichEditor content with custom blocks.
 *
 * Uses Filament's RichContentRenderer (via our Renderer subclass) for both
 * HTML strings and TipTap JSON. HtmlDiv/HtmlSpan extensions in the Renderer
 * ensure arbitrary div/span elements survive the roundtrip.
 *
 * Usage in Blade:
 *   {!! \Mmoollllee\Cms\Support\Content\RichText::render($content) !!}
 *
 * @see Renderer
 */
class RichText
{
    public static function render(string|array|null $content): string
    {
        if (blank($content)) {
            return '';
        }

        $html = Renderer::make($content)
            ->customBlocks([
                ButtonGroupBlock::class,
                NavigationCardGroupBlock::class,
            ])
            ->mergeTags(Shortcodes::mergeTagValues())
            ->toUnsafeHtml();

        $html = Shortcodes::render($html);

        return SpamprotectHtml::protectEmails($html);
    }

    /**
     * EDITOR-faithful HTML for a stored value: TipTap JSON (e.g. seeded content)
     * is serialized WITHOUT rendering — custom blocks stay round-trippable
     * `<div data-type="customBlock" data-id data-config>` elements, merge tags and
     * shortcodes stay tokens. Strings pass through. Use wherever content is loaded
     * into the RichEditor / its HTML source tab (which require strings).
     */
    public static function editorHtml(string|array|null $content): ?string
    {
        if (blank($content)) {
            return null;
        }

        if (is_string($content)) {
            return $content;
        }

        return Renderer::make($content)->getEditor()->getHTML();
    }
}
