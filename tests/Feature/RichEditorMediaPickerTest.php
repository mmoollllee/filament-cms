<?php

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\RichEditor\MediaLibraryPickerPlugin;
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
    $plugins = configuredRichEditor()->getPlugins();

    expect(array_map(fn ($plugin) => $plugin::class, $plugins))
        ->toContain(MediaLibraryPickerPlugin::class)
        // The upstream plugin underneath, so the picker action, its conversion
        // select and the alt-text handling are all still the vendor's.
        ->and(collect($plugins)->first(fn ($plugin) => $plugin instanceof MediaPlugin))
        ->not->toBeNull();
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

/*
 * The picker used to store `FileData::getKeyHash()` — the driver key encrypted
 * with APP_KEY. That only resolves in the environment that wrote it: pulling a
 * production database locally, or rotating the key, killed every picked image.
 * The id is portable, and the driver's own findFile() prefixes a bare key, so
 * reading still works.
 */

it('stores a portable item id, not an environment-bound key hash', function () {
    $tenant = actingAsMarketingPanelAdmin();
    $item = makeLibraryImage($tenant);

    $attributes = MediaLibraryPickerPlugin::make()
        ->driver(app(Cms::mediaDriver()))
        ->getEditorActionImageAttributes(['file' => 'media-library-item:'.$item->getKey()], []);

    expect($attributes['id'])->toBe((string) $item->getKey());
});

it('keeps a chosen conversion alongside the id', function () {
    $tenant = actingAsMarketingPanelAdmin();
    $item = makeLibraryImage($tenant);

    $attributes = MediaLibraryPickerPlugin::make()
        ->driver(app(Cms::mediaDriver()))
        ->getEditorActionImageAttributes(
            ['file' => 'media-library-item:'.$item->getKey(), 'conversion' => 'responsive'],
            [],
        );

    expect($attributes['id'])->toBe($item->getKey().'|responsive');
});
