<?php

/*
 * The TipTap JS extensions do not bundle their own TipTap. They import it from
 * resources/js/tiptap-core.js, which hands out the copy Filament's rich-editor
 * component publishes on `window.FilamentRichEditor.tiptap.core` — that is what
 * keeps every built file under a kilobyte instead of ~156 KB.
 *
 * Two things can undo that without a single existing test noticing: Filament
 * renaming the global (every extension then throws on import, and Filament only
 * console.errors it), and someone re-installing `@tiptap/core`, after which a
 * habitual `import … from '@tiptap/core'` builds green and silently inlines a
 * second runtime. Same convention as FilamentViewOverrideDriftTest: fail loudly,
 * with instructions.
 */

// On failure: Filament moved the global — point resources/js/tiptap-core.js at
// the new location, run `npm run build`, and update this test. Until then the
// editor loses its preserved markup, the link bubble and the `richtext` class.
it('pins the vendor global the extensions take TipTap from', function () {
    $richEditor = file_get_contents(dirname(__DIR__, 2).'/vendor/filament/forms/dist/components/rich-editor.js');

    expect($richEditor)->toContain('window.FilamentRichEditor.tiptap={core:');
});

// On failure: an extension imports TipTap directly again — change it back to
// `../tiptap-core.js`, and drop the dependency that let the build resolve it.
it('lets no extension source pull in a second TipTap runtime', function () {
    $sources = glob(dirname(__DIR__, 2).'/resources/js/{,tiptap-extensions/}*.js', GLOB_BRACE);

    expect($sources)->not->toBeEmpty();

    foreach ($sources as $source) {
        expect(preg_match('/^\s*import\s+.*\bfrom\s+[\'"]@tiptap/m', file_get_contents($source)))
            ->toBe(0, basename($source).' imports @tiptap directly instead of ../tiptap-core.js');
    }

    // Without the package installed, such an import fails the build instead of
    // resolving — the guard that makes the rule hold for future extensions too.
    $package = json_decode(file_get_contents(dirname(__DIR__, 2).'/package.json'), true);

    expect(array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []))
        ->not->toHaveKey('@tiptap/core');
});

// On failure: a built file carries an inlined runtime — check its imports, then
// re-run `npm run build`.
it('ships built extensions with no runtime inlined', function () {
    // A bundled TipTap + ProseMirror is ~156 KB; the largest hand-written
    // extension builds to ~2 KB. Anything past this ceiling is a second runtime,
    // not growth.
    $ceiling = 20 * 1024;

    $built = glob(dirname(__DIR__, 2).'/resources/dist/tiptap-extensions/*.js');

    expect($built)->not->toBeEmpty();

    foreach ($built as $file) {
        expect(filesize($file))->toBeLessThan($ceiling, basename($file).' looks like it bundles TipTap');
    }
});

// On failure: someone added or renamed an extension without rebuilding — run
// `npm run build` and commit resources/dist.
it('has every extension source built into resources/dist', function () {
    $sources = glob(dirname(__DIR__, 2).'/resources/js/tiptap-extensions/*.js');

    expect($sources)->not->toBeEmpty();

    foreach ($sources as $source) {
        $built = dirname(__DIR__, 2).'/resources/dist/tiptap-extensions/'.basename($source);

        expect($built)->toBeFile();

        // The built file must be the CURRENT source, not a leftover from before
        // a rename: match on the extension name the source declares.
        preg_match('/name:\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($source), $name);

        expect($name[1] ?? null)->not->toBeNull(basename($source).' declares no extension name');
        expect(file_get_contents($built))->toContain('name:"'.$name[1].'"');
    }
});
