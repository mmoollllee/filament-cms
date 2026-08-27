<?php

use Mmoollllee\Cms\Cms;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\RichEditor\Plugins\MediaPlugin;

/*
 * One upload route in the editor, not two. `mediaLibrary` picks an existing
 * Mediathek item AND uploads a new one — SelectFileAction builds its modal
 * topbar with `uploadAction(true)` — so the built-in `attachFiles` button would
 * only be a second, poorer way in: no folder, no alt text, no conversion.
 *
 * Dropping the button is not free, though. Filament derives
 * hasFileAttachments() from it being in the toolbar, and gates paste,
 * drag-and-drop and saveUploadedFileAttachment() on that. The provider sets the
 * flag directly instead, which is what these tests pin down.
 */

/** @return array<int, string> */
function richEditorToolbarButtonNames(): array
{
    return collect(configuredRichEditor()->getToolbarButtons())->flatten()->all();
}

it('offers the media library picker in the toolbar', function () {
    expect(richEditorToolbarButtonNames())->toContain('mediaLibrary');
});

it('registers the picker plugin on the editor', function () {
    $names = array_map(fn ($plugin) => $plugin::class, configuredRichEditor()->getPlugins());

    expect($names)->toContain(MediaPlugin::class);
});

it('leaves the redundant upload button out of the toolbar', function () {
    expect(richEditorToolbarButtonNames())->not->toContain('attachFiles');
});

it('keeps paste and drag-and-drop alive without that button', function () {
    // hasFileAttachments() is what the editor passes to JS as `canAttachFiles`,
    // and what saveUploadedFileAttachment() checks before consulting our
    // closure. Deriving it from the toolbar would have made it false here.
    expect(configuredRichEditor()->hasFileAttachments())->toBeTrue();
});

it('keeps them alive for a field that declares its own toolbar', function () {
    // toolbarButtons() clears every registered button modification, so anything
    // derived from the toolbar dies with it. The flag is set independently and
    // survives — this is the trap the button route left open.
    $editor = configuredRichEditor()->toolbarButtons([['bold', 'italic']]);

    // `linkPicker` proves the field's own list really did replace ours; the
    // plugin's `mediaLibrary` is re-added afterwards and stays either way.
    expect($editor->hasFileAttachments())->toBeTrue()
        ->and(collect($editor->getToolbarButtons())->flatten())->not->toContain('linkPicker');
});

it('falls back to the plain upload button without the library', function () {
    Cms::disableMediaLibrary();

    $names = richEditorToolbarButtonNames();

    expect($names)->toContain('attachFiles')
        ->and($names)->not->toContain('mediaLibrary')
        // Null, not false: without the library the flag stays Filament's own,
        // which the restored button satisfies.
        ->and(configuredRichEditor()->hasFileAttachments())->toBeTrue();
});
