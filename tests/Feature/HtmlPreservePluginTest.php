<?php

/*
 * Regression net for the HTML that editors type into the RichEditor's source
 * view: TipTap keeps only what an extension claims, so anything unclaimed is
 * silently flattened to its text on the next save. HtmlPreservePlugin claims
 * <div>/<span> with classes and <button> — the latter for frontend hooks that
 * bind by class, e.g. the consent banner's `.consent-control--open`.
 */

use Mmoollllee\Cms\Filament\RichEditor\HtmlPreservePlugin;
use Mmoollllee\Cms\Support\Content\RichText;

function htmlPreserveTipTapEditor(): Tiptap\Editor
{
    return tipTapEditorWithPlugins([HtmlPreservePlugin::make()]);
}

it('keeps a consent button through the editor roundtrip', function () {
    $html = '<p><button type="button" class="consent-control--open">Cookie-Einstellungen ändern</button></p>';

    $roundtripped = htmlPreserveTipTapEditor()->setContent($html)->getHTML();

    expect($roundtripped)->toContain('<button')
        ->toContain('class="consent-control--open"')
        ->toContain('type="button"')
        ->toContain('Cookie-Einstellungen ändern');
});

it('defaults a button without a type, so it cannot submit a surrounding form', function () {
    $roundtripped = htmlPreserveTipTapEditor()
        ->setContent('<p><button class="consent-control--open">Einstellungen</button></p>')
        ->getHTML();

    expect($roundtripped)->toContain('type="button"');
});

it('drops undeclared button attributes, inline handlers included', function () {
    $roundtripped = htmlPreserveTipTapEditor()
        ->setContent('<p><button class="x" onclick="alert(1)" data-evil="1">Klick</button></p>')
        ->getHTML();

    expect($roundtripped)->toContain('<button')
        ->not->toContain('onclick')
        ->not->toContain('data-evil');
});

it('renders the button on the frontend', function () {
    // The frontend renderer has its own extension list; a button preserved in
    // the editor but unknown there would reach the visitor as bare text.
    $frontend = RichText::render('<p><button type="button" class="consent-control--open">Cookie-Einstellungen ändern</button></p>');

    expect($frontend)->toContain('<button')
        ->toContain('class="consent-control--open"')
        ->toContain('Cookie-Einstellungen ändern');
});

it('ships every editor-side TipTap extension', function () {
    // Asset ids are matched by string between the plugin and the service
    // provider's FilamentAsset::register() — a mismatch throws only at request
    // time, while the server-side roundtrip tests above stay green.
    expect(app(HtmlPreservePlugin::class)->getTipTapJsExtensions())
        ->toHaveCount(4)
        ->and(implode(' ', app(HtmlPreservePlugin::class)->getTipTapJsExtensions()))
        ->toContain('html-button')
        ->toContain('html-div')
        ->toContain('html-span')
        ->toContain('rich-text-surface');
});

it('still preserves the div and span markup the plugin was built for', function () {
    $html = '<div class="callout"><p>Hinweis mit <span class="pill">Label</span></p></div>';

    $roundtripped = htmlPreserveTipTapEditor()->setContent($html)->getHTML();

    expect($roundtripped)->toContain('<div class="callout">')
        ->toContain('<span class="pill">');
});
