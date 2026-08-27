<?php

use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Schema;
use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\RichEditor\Plugins\MediaPlugin;
use Workbench\App\Filament\Pages\Dashboard;

/*
 * Two ways an image gets into rich text, and they are not alternatives:
 * `mediaLibrary` PICKS an existing Mediathek item, `attachFiles` uploads a new
 * one. Filament derives hasFileAttachments() from the second button being
 * present, so losing it also loses paste and drag-and-drop — and MediaPlugin
 * strips that button from any toolbar it is registered on.
 */

/** The toolbar as the panel actually resolves it, plugin modifications applied. */
function configuredEditor(): RichEditor
{
    return RichEditor::make('content')->container(Schema::make(app(Dashboard::class)));
}

/** @return array<int, string> */
function toolbarButtonNames(RichEditor $editor): array
{
    return collect($editor->getToolbarButtons())->flatten()->all();
}

it('offers the media library picker in the toolbar', function () {
    expect(toolbarButtonNames(configuredEditor()))->toContain('mediaLibrary');
});

it('registers the picker plugin on the editor', function () {
    $names = array_map(fn ($plugin) => $plugin::class, configuredEditor()->getPlugins());

    expect($names)->toContain(MediaPlugin::class);
});

it('keeps the upload button the picker plugin would remove', function () {
    // MediaPlugin::getDisabledToolbarButtons() returns ['attachFiles'], and a
    // plugin's modifications are applied to whatever toolbarButtons() declared.
    // Re-enabling has to happen AFTER that call, which clears every earlier
    // modification.
    expect(toolbarButtonNames(configuredEditor()))->toContain('attachFiles');
});

it('keeps paste and drag-and-drop alive', function () {
    // hasFileAttachments() is what the editor passes to JS as `canAttachFiles`.
    expect(configuredEditor()->hasFileAttachments())->toBeTrue();
});

it('falls back to the plain upload button without the library', function () {
    Cms::disableMediaLibrary();

    $names = toolbarButtonNames(configuredEditor());

    expect($names)->toContain('attachFiles')
        ->and($names)->not->toContain('mediaLibrary');
});
