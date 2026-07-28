<?php

/*
 * Shortcode attribute values end up inside HTML tags — the [logo] handler hands
 * `class` to blade-icons, which writes it into the <svg> tag unescaped.
 *
 * parseAttributes() has to html_entity_decode() first (editors' quotes arrive as
 * &quot; through TipTap), and that decoding also undoes any e() a calling view
 * already applied. So the parser must re-escape, or `<`/`>` survive into the
 * attribute value and close the tag — stored XSS reachable by any tenant editor,
 * hitting public visitors and the panel's block preview alike.
 *
 * The guard lives in the shared parser, so the tests drive it both through the
 * real [logo] sink (blade-icons, with a throwaway icon set registered below —
 * the workbench ships no icons) and through a probe shortcode standing in for
 * any handler an app registers later.
 */

use BladeUI\Icons\Factory;
use Mmoollllee\Cms\Support\Shortcodes;

beforeEach(function () {
    Shortcodes::reset();

    // Minimal icon set so `svg('image-logo')` resolves — the shortcode hands it
    // the class attribute unescaped, which is the sink under test.
    $iconDir = sys_get_temp_dir().'/cms-icons-'.getmypid();
    @mkdir($iconDir, 0777, true);
    file_put_contents($iconDir.'/image-logo.svg', '<svg viewBox="0 0 10 10"></svg>');
    app(Factory::class)->add('default', ['path' => $iconDir, 'prefix' => '']);

    // Mirrors a handler that drops the attribute straight into a tag.
    Shortcodes::register('probe', fn (array $attrs): string => '<span class="'.($attrs['class'] ?? '').'"></span>');
});

afterEach(fn () => Shortcodes::reset());

it('escapes the class attribute of the real logo shortcode', function () {
    $out = Shortcodes::render('[logo class="x><script>alert(1)</script>"]');

    expect($out)
        ->toContain('<svg')
        ->and($out)->not->toContain('<script')
        ->and($out)->toContain('&lt;script&gt;');
});

it('still renders the logo with ordinary classes', function () {
    $out = Shortcodes::render('[logo class="h-8 w-auto"]');

    expect($out)->toContain('h-8 w-auto')
        ->and($out)->toContain('<svg');
});

it('does not let a shortcode attribute break out of the tag', function (string $payload) {
    $out = Shortcodes::render('[probe class="'.$payload.'"]');

    expect($out)
        ->not->toContain('<script')
        ->not->toContain('onload=')
        ->not->toContain('onerror=');
})->with([
    'tag breakout' => ['x><script>alert(1)</script>'],
    'entity-encoded breakout' => ['x&#62;&#60;script&#62;alert(1)&#60;/script&#62;'],
    'quote breakout' => ['x" onload="alert(1)'],
    'single-quote breakout' => ["x' onload='alert(1)"],
]);

it('keeps the injected markup inert as text instead of dropping it silently', function () {
    $out = Shortcodes::render('[probe class="x><script>alert(1)</script>"]');

    // The value survives — escaped — so the shortcode still renders and an
    // editor sees their (wrong) input rather than nothing at all.
    expect($out)->toContain('&lt;script&gt;');
});

it('still applies ordinary CSS classes untouched', function () {
    $out = Shortcodes::render('[probe class="h-8 w-auto text-primary"]');

    expect($out)->toContain('class="h-8 w-auto text-primary"');
});

it('leaves text without shortcodes untouched', function () {
    $html = '<p>Ein Text mit einer eckigen [Klammer] ohne Shortcode.</p>';

    expect(Shortcodes::render($html))->toBe($html);
});
