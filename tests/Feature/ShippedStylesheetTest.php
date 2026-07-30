<?php

use Illuminate\Support\Facades\File;

/*
 * builder.css and versionable.css ship PRECOMPILED and are registered as Filament
 * assets — no build step ever parses them, so a syntax error reaches every
 * consuming panel unnoticed and silently drops every rule after it.
 *
 * The failure mode these tests exist for: a comment whose TEXT contains a closing
 * delimiter terminates early. Writing "max-h-*" immediately before "/min-h-*" is
 * enough. Everything after that point is then parsed as CSS, is invalid, and the
 * rest of the stylesheet is lost.
 *
 * Note on assertion style: expect()->toContain() takes NEEDLES, not a failure
 * message — passing one as a second argument silently weakens the assertion. The
 * offender lists below keep the failure output useful without that trap.
 */

/** @return array<int, string> */
function shippedStylesheets(): array
{
    return File::glob(__DIR__.'/../../resources/css/*.css');
}

/** Strip comments the way a parser does: each one ends at its FIRST closing delimiter. */
function stripCssComments(string $path): string
{
    return preg_replace('#/\*.*?\*/#s', '', File::get($path));
}

it('ships stylesheets whose comments end where they appear to end', function () {
    $offenders = [];

    foreach (shippedStylesheets() as $path) {
        $stripped = stripCssComments($path);

        if (str_contains($stripped, '*/') || str_contains($stripped, '/*')) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([]);
});

it('ships stylesheets with balanced braces', function () {
    $offenders = [];

    foreach (shippedStylesheets() as $path) {
        $stripped = stripCssComments($path);

        if (substr_count($stripped, '{') !== substr_count($stripped, '}')) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([]);
});

it('caps preview media height through an overridable custom property', function () {
    $css = File::get(__DIR__.'/../../resources/css/builder.css');

    // The cap belongs on the media element: layout presets put their height
    // utilities on the preview container, and a container rule here would
    // outrank every one of them, including the escape hatches.
    expect($css)->toContain('.fi-fo-builder-item-preview img')
        ->and($css)->toContain('.fi-fo-builder-item-preview video')
        ->and($css)->toContain('max-height: var(--cms-preview-media-max-height)')
        ->and($css)->toContain('--cms-preview-media-max-height:');
});
