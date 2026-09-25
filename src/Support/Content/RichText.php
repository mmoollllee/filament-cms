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
     * Whether a stored rich-text value holds nothing an editor could lose: null, an
     * empty string/array, or markup made only of empty paragraphs, line breaks,
     * whitespace and &nbsp; — what the editor leaves behind once its text is deleted.
     *
     * Deliberately conservative, because the answer decides whether a form may HIDE
     * the field, and a hidden field is not dehydrated: its value disappears on the
     * next save. Anything else — an image, an embed, a custom block, a bare <div> —
     * counts as content, even without a single character of text.
     */
    public static function isBlank(string|array|null $content): bool
    {
        $html = static::editorHtml($content);

        if (blank($html)) {
            return true;
        }

        // One character class for all whitespace (U+00A0 included — under /u `\s`
        // matches it too) and a possessive loop: every character can be consumed
        // exactly one way, so a paragraph that merely STARTS with a long run of
        // spaces fails fast instead of backtracking until PCRE gives up.
        $withoutEmptyParagraphs = preg_replace(
            '#<p(?:\s[^>]*)?>(?:[\s\x{00A0}]|&nbsp;|&\#160;|<br\s*/?>)*+</p>#iu',
            '',
            $html,
        );

        $rest = $withoutEmptyParagraphs === null
            ? null
            : preg_replace('#<br\s*/?>|&nbsp;|&\#160;|[\s\x{00A0}]++#iu', '', $withoutEmptyParagraphs);

        // A regex failure must never answer "blank" — the answer may hide a field.
        return $rest === '';
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
