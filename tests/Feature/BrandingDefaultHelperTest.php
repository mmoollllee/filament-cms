<?php

function renderBrandingHint(array $data): string
{
    return view('cms::tenancy.branding-default-helper', array_merge([
        'defaultDomain' => 'example.test',
        'showDefaultPreview' => true,
    ], $data))->render();
}

it('shows the inheritance hint when an inherited value exists', function () {
    $html = renderBrandingHint(['previewType' => 'text', 'previewText' => 'Acme GmbH']);

    expect($html)
        ->toContain('aus example.test')
        ->toContain('Acme GmbH');
});

it('hides the inheritance hint for an empty text default', function () {
    $html = renderBrandingHint(['previewType' => 'text', 'previewText' => null]);

    expect($html)->not->toContain('aus example.test');
});

it('hides the inheritance hint for an empty asset default', function () {
    $html = renderBrandingHint(['previewType' => 'asset', 'previewUrl' => null]);

    expect($html)->not->toContain('aus example.test');
});

it('still renders the description when there is no inherited value', function () {
    $html = renderBrandingHint([
        'previewType' => 'text',
        'previewText' => null,
        'description' => 'Optionaler Hinweis.',
    ]);

    expect($html)
        ->toContain('Optionaler Hinweis.')
        ->not->toContain('aus example.test');
});

it('renders the text preview flush (no leading blank line that inflates the box)', function () {
    $html = renderBrandingHint(['previewType' => 'text', 'previewText' => '  Acme GmbH  ']);

    // The value sits directly against the tags — whitespace-pre-line can't turn stray
    // template/indentation whitespace into a tall, empty-looking box.
    expect($html)->toContain('>Acme GmbH</span>');
});
