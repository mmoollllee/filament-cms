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

    public function addAttributes(): array
    {
        return [
            'class' => [
                'default' => null,
                'parseHTML' => fn (\DOMElement $DOMNode): ?string => $DOMNode->getAttribute('class') ?: null,
                'renderHTML' => function ($attributes): array {
                    $attributes = (array) $attributes;

                    return array_filter(['class' => $attributes['class'] ?? null]);
                },
            ],
        ];
    }

    public function parseHTML(): array
    {
        return [
            [
                'tag' => 'span',
                'getAttrs' => fn (\DOMElement $DOMNode): null => null,
            ],
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
