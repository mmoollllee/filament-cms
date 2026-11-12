<?php

/*
 * The package replaces Filament's builder rendering with vendored Blade
 * equivalents (resources/overrides/filament-forms/…), re-entered via the
 * explicit ->view() in BlockBuilder::make() + prependNamespace(). A Filament
 * update that changes the ORIGINAL rendering would be silently swallowed by
 * the override — this guard turns that silence into a failing test.
 *
 * Since Filament 5.7 the builder has no vendor Blade view: it renders via
 * Builder::toEmbeddedHtml() / generateBlockPickerHtml(), wrapped by
 * Field::wrapEmbeddedHtml() (whose wrapper contract the override reproduces
 * as <x-dynamic-component :component="$fieldWrapperView" label-tag="div">).
 * The guard hashes each METHOD SOURCE via reflection — immune to unrelated
 * methods being added or the file being reordered — plus the still-existing
 * block-picker Blade view.
 *
 * When this test fails after a `composer update`:
 *  1. diff the flagged vendor method (or the picker view) against the
 *     previous baseline to see what Filament changed,
 *  2. translate/re-apply the change to the override in
 *     resources/overrides/filament-forms/ (all cms divergences are wrapped in
 *     `cms:start` / `cms:end` markers — everything else must match vendor),
 *  3. update the hash + baseline version below.
 */

use Illuminate\Support\Facades\File;

const FILAMENT_VIEW_BASELINE = 'v5.11.0';

dataset('embedded render methods', [
    'Builder::toEmbeddedHtml' => [
        \Filament\Forms\Components\Builder::class,
        'toEmbeddedHtml',
        '071e7584056dea2754c9d7a48681d2d1ce99a17312bd7b17136e90bf22de9839',
    ],
    // The INLINE picker HTML; the override renders through the published Blade view
    // below, with the props this method hands it (searchable, searchPrompt, …).
    'Builder::generateBlockPickerHtml' => [
        \Filament\Forms\Components\Builder::class,
        'generateBlockPickerHtml',
        '9dd56f0de11dcc9e3224526d3e5f1343b5ac8f77537f22058a490e4830382bc4',
    ],
    // Since v5.7 the PHP wrapper finds a field's errors differently, gives the error
    // message an id ({id}-error) and adds a screen-reader text for required fields
    // ({id}-required); the override renders the field wrapper view, which carries
    // the same changes.
    'Field::wrapEmbeddedHtml' => [
        \Filament\Forms\Components\Field::class,
        'wrapEmbeddedHtml',
        '550bcb6f6e127c9ed3bf7a0a9a4aa8d4c2251747ed1a4428f1cbe361fb2b5f15',
    ],
]);

it('matches the vendor baseline the builder override was translated from', function (string $class, string $method, string $expectedHash) {
    expect(method_exists($class, $method))->toBeTrue(
        "{$class}::{$method}() no longer exists — Filament changed the builder rendering "
            .'architecture again; rebuild resources/overrides/filament-forms/components/builder.blade.php '
            .'against the new mechanism and update this guard.',
    );

    $reflection = new ReflectionMethod($class, $method);
    $lines = file($reflection->getFileName());
    $source = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

    expect(hash('sha256', $source))->toBe(
        $expectedHash,
        "Filament changed {$class}::{$method}() since baseline ".FILAMENT_VIEW_BASELINE.'. '
            .'Translate the change into resources/overrides/filament-forms/components/builder.blade.php '
            .'(keep the cms:start/cms:end blocks), then update the hash in this test.',
    );
})->with('embedded render methods');

it('matches the vendor baseline the block-picker view override was vendored from', function () {
    $absolute = dirname(__DIR__, 2).'/vendor/filament/forms/resources/views/components/builder/block-picker.blade.php';

    expect(File::exists($absolute))->toBeTrue('Vendor block-picker view is missing — did the Filament view move?');

    expect(hash_file('sha256', $absolute))->toBe(
        'd6a553c0104d51b6663b1e80fb890ed37f04cc0b7d53490eb1026d83e603e4dc',
        'Filament changed the block-picker view since baseline '.FILAMENT_VIEW_BASELINE.'. '
            .'Re-apply the vendor changes to the override in resources/overrides/filament-forms/ '
            .'(keep the cms:start/cms:end blocks), then update the hash in this test.',
    );
});

/*
 * Same guard, different vendor: the package's link-suggestions field wrapper
 * (cms::filament.forms.link-suggestions-wrapper) re-implements the markup half
 * of defstudio/filament-searchable-input while reusing its Alpine component.
 * If the vendor changes the Alpine contract (suggestions shape, method names)
 * or its wrapper markup, our wrapper must be reconciled by hand.
 */
const SEARCHABLE_INPUT_BASELINE = 'v5.0.2';

dataset('searchable-input contract files', [
    'alpine component' => [
        'vendor/defstudio/filament-searchable-input/resources/js/components/searchable-input.js',
        '7cac88ba04b05b4ee577b814c3547a824aeed7eea066ac0427cca238e9db1c31',
    ],
    'wrapper view' => [
        'vendor/defstudio/filament-searchable-input/resources/views/components/wrapper.blade.php',
        '0eaf1b208534f0bfa88bee2912cda1c14229c5310f2b961a3d1090f84cfb22b1',
    ],
]);

it('matches the searchable-input baseline the link-suggestions wrapper was built against', function (string $vendorPath, string $expectedHash) {
    $absolute = dirname(__DIR__, 2).'/'.$vendorPath;

    expect(File::exists($absolute))->toBeTrue("Vendor file {$vendorPath} is missing — did the searchable-input package restructure?");

    expect(hash_file('sha256', $absolute))->toBe(
        $expectedHash,
        "defstudio/filament-searchable-input changed {$vendorPath} since baseline ".SEARCHABLE_INPUT_BASELINE.'. '
            .'Reconcile resources/views/filament/forms/link-suggestions-wrapper.blade.php with the new '
            .'Alpine contract/markup, then update the hash in this test.',
    );
})->with('searchable-input contract files');
