<?php

namespace Mmoollllee\Cms\Tiptap\Marks;

use Tiptap\Core\Mark;
use Tiptap\Utils\HTML;

/**
 * Generic TipTap mark that preserves <span> elements with class attributes.
 *
 * Allows inline spans with arbitrary classes (e.g. pill, eyebrow)
 * to survive TipTap's HTML→JSON→HTML roundtrip.
 */
class HtmlSpan extends Mark
{
    public static $name = 'htmlSpan';

    public static $priority = 40;

    /**
     * `class` gets tiptap-php's default handling — read the DOM attribute on
     * parse, render it back unless it is null — so only the default needs
     * spelling out.
     */
    public function addAttributes(): array
    {
        return [
            'class' => ['default' => null],
        ];
    }

    public function parseHTML(): array
    {
        return [
            ['tag' => 'span'],
        ];
    }

    public function renderHTML($mark, $HTMLAttributes = []): array
    {
        return [
            'span',
            HTML::mergeAttributes($HTMLAttributes),
            0,
        ];
    }
}
