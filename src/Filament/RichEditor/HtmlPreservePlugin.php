<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Facades\FilamentAsset;
use Mmoollllee\Cms\Tiptap\Marks\HtmlSpan;
use Mmoollllee\Cms\Tiptap\Nodes\HtmlDiv;
use Tiptap\Core\Extension;

/**
 * RichEditor plugin that preserves arbitrary <div> and <span> elements
 * with class attributes through TipTap's HTML→JSON→HTML roundtrip.
 *
 * Registers both PHP (server-side rendering) and JS (client-side editor)
 * TipTap extensions so that custom HTML from seeders or raw editing
 * survives without being stripped.
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
            app(HtmlDiv::class),
            app(HtmlSpan::class),
        ];
    }

    /** @return array<string> */
    public function getTipTapJsExtensions(): array
    {
        return [
            FilamentAsset::getScriptSrc('tiptap-html-div', 'mmoollllee/filament-cms'),
            FilamentAsset::getScriptSrc('tiptap-html-span', 'mmoollllee/filament-cms'),
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
