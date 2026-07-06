<?php

/*
 * Regression net for RichText::editorHtml(): stored TipTap JSON (seeded custom-
 * block content) must load into the editor tabs as a STRING — the RichEditor
 * shows nothing for arrays and CodeMirror crashes on them
 * ("(e.doc||'').split is not a function") — and the serialized editor HTML must
 * keep custom blocks round-trippable AND render correctly on the frontend.
 */

use Mmoollllee\Cms\Support\Content\RichText;

function tiptapNavCardsDoc(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Intro']]],
        ['type' => 'customBlock', 'attrs' => ['id' => 'navigationCardGroup', 'config' => ['cards' => [
            ['label' => 'Features', 'href' => '/features', 'text' => 'All features.'],
        ]]]],
    ]];
}

it('serializes TipTap JSON to editor-faithful HTML (custom blocks stay data-attribute divs)', function () {
    $html = RichText::editorHtml(tiptapNavCardsDoc());

    expect($html)->toBeString()
        ->and($html)->toContain('<p>Intro</p>')
        ->and($html)->toContain('data-type="customBlock"')
        ->and($html)->toContain('data-id="navigationCardGroup"')
        ->and($html)->toContain('data-config=')
        // NOT the rendered frontend markup — the block must stay editable.
        ->and($html)->not->toContain('nav-cards');
});

it('passes strings through and nulls blank values', function () {
    expect(RichText::editorHtml('<p>Hi</p>'))->toBe('<p>Hi</p>')
        ->and(RichText::editorHtml(null))->toBeNull()
        ->and(RichText::editorHtml([]))->toBeNull();
});

it('round-trips: the editor HTML renders the same custom block on the frontend', function () {
    $editorHtml = RichText::editorHtml(tiptapNavCardsDoc());

    // Feeding the editor-serialized HTML back through the frontend renderer must
    // produce the rendered custom block — proving save-after-load loses nothing.
    $frontend = RichText::render($editorHtml);

    expect($frontend)->toContain('nav-cards')
        ->and($frontend)->toContain('Features');
});
