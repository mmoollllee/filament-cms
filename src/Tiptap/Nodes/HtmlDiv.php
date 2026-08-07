<?php

namespace Mmoollllee\Cms\Tiptap\Nodes;

use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

/**
 * Generic TipTap node that preserves <div> elements with class attributes.
 *
 * Catches any <div> that has a class attribute and isn't handled by
 * more specific extensions (lead, grid, customBlock, etc.).
 * This allows arbitrary HTML from seeders or raw editing to survive
 * TipTap's HTML→JSON→HTML roundtrip.
 */
class HtmlDiv extends Node
{
    public static $name = 'htmlDiv';

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
            [
                'tag' => 'div',
                'getAttrs' => function (\DOMElement $DOMNode): ?bool {
                    $class = $DOMNode->getAttribute('class');

                    if (blank($class)) {
                        return false;
                    }

                    $classes = explode(' ', $class);

                    // Reject divs handled by other extensions.
                    $reservedClasses = ['lead', 'grid-layout', 'grid-column', 'ProseMirror-focused'];

                    if (array_intersect($classes, $reservedClasses) !== []) {
                        return false;
                    }

                    // Reject custom blocks (handled by CustomBlockExtension).
                    if ($DOMNode->hasAttribute('data-type') && $DOMNode->getAttribute('data-type') === 'customBlock') {
                        return false;
                    }

                    return null; // Accept
                },
            ],
        ];
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return [
            'div',
            HTML::mergeAttributes($HTMLAttributes),
            0,
        ];
    }
}
