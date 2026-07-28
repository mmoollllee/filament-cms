<?php

/*
 * SpamprotectHtml rebuilds editorial mailto:/tel: anchors through Blade.
 *
 * The label comes from editorial content, so it must never reach Blade as
 * TEMPLATE SOURCE — Blade compiles its first argument, and e() does not escape
 * `{{ }}`, `{!! !!}` or `@php`. A concatenated label therefore executed PHP on
 * the server; these tests pin the data-binding that closed it, plus the plain
 * behaviour that must keep working.
 */

use Mmoollllee\Cms\Support\SpamprotectHtml;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

/** The visible text of the rewritten anchor. */
function protectedLabel(string $html): string
{
    $out = SpamprotectHtml::protectEmails($html);

    preg_match('#<a\b[^>]*>(.*?)</a>#s', $out, $matches);

    return trim(preg_replace('/\s+/', ' ', $matches[1] ?? ''));
}

it('does not execute Blade expressions smuggled through a mailto label', function (string $expression) {
    $label = protectedLabel('<p><a href="mailto:info@example.test">'.$expression.'</a></p>');

    expect($label)->toBe($expression);
})->with([
    'arithmetic' => ['{{ 6*7 }}'],
    'function call' => ['{{ phpversion() }}'],
    'path disclosure' => ['{{ base_path() }}'],
    'unescaped echo' => ['{!! phpversion() !!}'],
    'raw php block' => ['@php echo phpversion(); @endphp'],
]);

it('does not execute Blade expressions smuggled through a tel label', function () {
    $label = protectedLabel('<p><a href="tel:+4912345">{{ 6*7 }}</a></p>');

    expect($label)->toBe('{{ 6*7 }}')
        ->and($label)->not->toBe('42');
});

it('never leaks the rendered result of an injected expression', function () {
    $out = SpamprotectHtml::protectEmails(
        '<p><a href="mailto:info@example.test">{{ phpversion() }}|{{ base_path() }}</a></p>'
    );

    expect($out)
        ->not->toContain(PHP_VERSION)
        ->and($out)->not->toContain(base_path());
});

it('still escapes HTML in the label', function () {
    $label = protectedLabel('<p><a href="mailto:info@example.test">a &lt;b&gt; c</a></p>');

    // DOM textContent decodes the entity; the rewritten anchor must re-escape it
    // rather than emit a live <b> element.
    expect($label)->toBe('a &lt;b&gt; c');
});

it('keeps rewriting ordinary contact links', function () {
    $out = SpamprotectHtml::protectEmails('<p><a href="mailto:info@example.test">Schreib uns</a></p>');

    expect($out)
        ->toContain('data-spamprotect-token')
        ->and($out)->toContain('Schreib uns')
        // The address itself must not survive in the markup — that is the point
        // of the component.
        ->and($out)->not->toContain('info@example.test');
});

it('leaves markup without contact links untouched', function () {
    $html = '<p>Kein Kontaktlink, aber {{ 6*7 }} als Text.</p>';

    expect(SpamprotectHtml::protectEmails($html))->toBe($html);
});
