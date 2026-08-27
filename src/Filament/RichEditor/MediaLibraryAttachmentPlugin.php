<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\HasFileAttachmentProvider;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Mmoollllee\Cms\Support\Media\MediaLibraryFileAttachmentProvider;
use Tiptap\Core\Extension;

/**
 * Carries {@see MediaLibraryFileAttachmentProvider} to both ends of the rich
 * editor.
 *
 * It adds no tools and no extensions — it exists purely because the provider
 * has to be identical in the editor and in the renderer, and a plugin is the
 * one seam Filament reads from both: `RichContentAttribute` consults it when
 * saving an upload, `RichContentRenderer` when turning a `data-id` back into a
 * URL. Setting the provider on the field alone would store ids the frontend
 * cannot resolve, and every embedded image would render blank.
 */
class MediaLibraryAttachmentPlugin implements HasFileAttachmentProvider, RichContentPlugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getFileAttachmentProvider(): ?FileAttachmentProvider
    {
        return MediaLibraryFileAttachmentProvider::make();
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [];
    }
}
