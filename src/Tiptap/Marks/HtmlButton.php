<?php

namespace Mmoollllee\Cms\Tiptap\Marks;

use Tiptap\Core\Mark;
use Tiptap\Utils\HTML;

/**
 * Generic TipTap mark that preserves <button> elements.
 *
 * Editorial buttons are hooks for frontend scripts that bind by class — the
 * consent banner's `<button type="button" class="consent-control--open">` being
 * the case this exists for. Typed into the RichEditor's HTML source view, they
 * would otherwise be flattened to their label by TipTap's HTML→JSON→HTML
 * roundtrip.
 *
 * Only `class` and `type` survive: every other attribute is undeclared and
 * therefore dropped on parse, which keeps inline handlers (`onclick`) out of
 * stored content.
 *
 * A mark, not a node — like {@see HtmlSpan}: the button wraps inline text,
 * stays inside its paragraph and keeps its label editable in the editor.
 * The editor-side counterpart is the `html-button` TipTap JS extension.
 *
 * @see \Mmoollllee\Cms\Filament\RichEditor\HtmlPreservePlugin
 */
class HtmlButton extends Mark
{
    public static $name = 'htmlButton';

    public static $priority = 40;

    /**
     * Both attributes get tiptap-php's default handling — read the DOM
     * attribute on parse, render it back unless it is null — so only the
     * defaults need spelling out. `type` defaults to `button`: an editorial
     * button inside a form must never submit it, and the attribute is easy to
     * forget when typing HTML.
     */
    public function addAttributes(): array
    {
        return [
            'class' => ['default' => null],
            'type' => ['default' => 'button'],
        ];
    }

    public function parseHTML(): array
    {
        return [
            ['tag' => 'button'],
        ];
    }

    public function renderHTML($mark, $HTMLAttributes = []): array
    {
        return [
            'button',
            HTML::mergeAttributes($HTMLAttributes),
            0,
        ];
    }
}
