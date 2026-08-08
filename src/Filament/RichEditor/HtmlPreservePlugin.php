<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Facades\FilamentAsset;
use Mmoollllee\Cms\Tiptap\Marks\HtmlButton;
use Mmoollllee\Cms\Tiptap\Marks\HtmlSpan;
use Mmoollllee\Cms\Tiptap\Nodes\HtmlDiv;
use Tiptap\Core\Extension;

/**
 * RichEditor plugin that preserves arbitrary <div> and <span> elements with
 * class attributes — plus <button> elements — through TipTap's HTML→JSON→HTML
 * roundtrip.
 *
 * Registers both PHP (server-side rendering) and JS (client-side editor)
 * TipTap extensions so that custom HTML from seeders or raw editing
 * survives without being stripped.
 *
 * Plugins are Filament's only hook for loading TipTap JS, so this one — the
 * plugin every panel RichEditor gets — also carries the package's editor-only
 * extension: `rich-text-surface` marks the editing surface as rich-text
 * content (that JS file explains which element and why).
 */
class HtmlPreservePlugin implements RichContentPlugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    /** @return array<Extension> */
    public function getTipTapPhpExtensions(): array
    {
        return [
            app(HtmlButton::class),
            app(HtmlDiv::class),
            app(HtmlSpan::class),
        ];
    }

    /** @return array<string> */
    public function getTipTapJsExtensions(): array
    {
        return [
            FilamentAsset::getScriptSrc('tiptap-html-button', 'mmoollllee/filament-cms'),
            FilamentAsset::getScriptSrc('tiptap-html-div', 'mmoollllee/filament-cms'),
            FilamentAsset::getScriptSrc('tiptap-html-span', 'mmoollllee/filament-cms'),
            // Editor-only, no PHP half: marks the surface `.richtext`.
            FilamentAsset::getScriptSrc('tiptap-rich-text-surface', 'mmoollllee/filament-cms'),
        ];
    }

    /** @return array<RichEditorTool> */
    public function getEditorTools(): array
    {
        return [];
    }

    /** @return array<Action> */
    public function getEditorActions(): array
    {
        return [];
    }
}
