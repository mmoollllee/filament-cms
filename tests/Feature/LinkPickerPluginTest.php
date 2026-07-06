<?php

/*
 * Regression net for the LinkPicker editor action: it must actually run the
 * editor commands (setLink / unsetLink) — an earlier version built the
 * attribute array and silently discarded it, so the modal's "save" did nothing.
 */

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Mmoollllee\Cms\Filament\RichEditor\LinkPickerPlugin;

function linkPickerActionClosure(): Closure
{
    $action = collect(app(LinkPickerPlugin::class)->getEditorActions())
        ->first(fn ($action) => $action->getName() === 'linkPicker');

    expect($action)->not->toBeNull();

    return $action->getActionFunction();
}

it('applies the link via setLink editor commands', function () {
    $commands = null;

    $editor = Mockery::mock(RichEditor::class);
    $editor->shouldReceive('runCommands')
        ->once()
        ->withArgs(function (array $cmds, array $editorSelection) use (&$commands): bool {
            $commands = $cmds;

            return true;
        });

    $closure = linkPickerActionClosure();

    $closure(
        arguments: ['editorSelection' => ['head' => 3, 'anchor' => 7]],
        data: ['href' => '/kontakt', 'title' => 'Kontakt', 'class' => 'btn', 'rel' => null, 'wire_navigate' => true],
        component: $editor,
    );

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toBeInstanceOf(EditorCommand::class)
        ->and($commands[0]->name)->toBe('setLink')
        ->and($commands[0]->arguments[0])->toMatchArray([
            'href' => '/kontakt',
            'title' => 'Kontakt',
            'class' => 'btn',
            'wire:navigate' => true,
        ]);
});

it('declares title and wire:navigate on both sides of the link mark', function () {
    // Server side: the PHP mark parses + renders the extra attributes.
    $phpAttributes = array_keys(app(\Mmoollllee\Cms\Tiptap\Marks\LinkPicker::class)->addAttributes());

    expect($phpAttributes)->toContain('title')->toContain('wire:navigate');

    // Editor side: the plugin ships the link-attributes JS extension, so the
    // attributes survive client-side re-editing (setLink drops undeclared attrs).
    expect(app(LinkPickerPlugin::class)->getTipTapJsExtensions())
        ->toHaveCount(1)
        ->and(app(LinkPickerPlugin::class)->getTipTapJsExtensions()[0])->toContain('link-attributes');
});

it('removes the link via unsetLink when the href is cleared, extending a collapsed selection', function () {
    $commands = null;

    $editor = Mockery::mock(RichEditor::class);
    $editor->shouldReceive('runCommands')
        ->once()
        ->withArgs(function (array $cmds, array $editorSelection) use (&$commands): bool {
            $commands = $cmds;

            return true;
        });

    $closure = linkPickerActionClosure();

    // head === anchor → cursor inside the link without a selection.
    $closure(
        arguments: ['editorSelection' => ['head' => 5, 'anchor' => 5]],
        data: ['href' => null],
        component: $editor,
    );

    expect(collect($commands)->map(fn (EditorCommand $c) => $c->name)->all())
        ->toBe(['extendMarkRange', 'unsetLink']);
});
