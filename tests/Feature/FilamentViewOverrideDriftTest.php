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

const FILAMENT_VIEW_BASELINE = 'v5.7.1';

dataset('embedded render methods', [
    'Builder::toEmbeddedHtml' => [
        \Filament\Forms\Components\Builder::class,
        'toEmbeddedHtml',
        'f8a7c1351bd303499c8ec8ae90bf0fa7ee2dcd1a365f46595d3eb7aa02b82e88',
    ],
    'Builder::generateBlockPickerHtml' => [
        \Filament\Forms\Components\Builder::class,
        'generateBlockPickerHtml',
        'bfa8d87ac26089574f5f38775b5f60fead54f376fb0614910df93b730e5aad5e',
    ],
    'Field::wrapEmbeddedHtml' => [
        \Filament\Forms\Components\Field::class,
        'wrapEmbeddedHtml',
        '6796bfb5f45a5fe3fb5f5eafdd1c550ea496f03ece16a6da7b3458dfeffbc82b',
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
        '3bfe9847733b1e995416a638eb40eb2c2083ea56da77ff4a6da17f6ac0fcdff0',
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
