<?php

/*
 * The package ships vendored copies of two Filament builder views
 * (resources/overrides/filament-forms/…) that shadow the originals via
 * prependNamespace(). A Filament update that changes the ORIGINAL views would be
 * silently swallowed by the override — this guard turns that silence into a
 * failing test.
 *
 * When this test fails after a `composer update`:
 *  1. diff the vendor view against the previous baseline to see what Filament changed,
 *  2. re-apply the change to the override copy (all cms divergences are wrapped in
 *     `cms:start` / `cms:end` markers — everything else must match vendor),
 *  3. update the hash + baseline version below.
 */

use Illuminate\Support\Facades\File;

const FILAMENT_VIEW_BASELINE = 'v5.6.8';

dataset('overridden vendor views', [
    'builder' => [
        'vendor/filament/forms/resources/views/components/builder.blade.php',
        'ad09bf8d8843778ac53929a403589caeafa00db0a639d72a278f30a197917a36',
    ],
    'block-picker' => [
        'vendor/filament/forms/resources/views/components/builder/block-picker.blade.php',
        'a6c0fcbf7e8f944e590c382dbe8bca13963e56afaddf28f325e7c4ab923bd315',
    ],
]);

it('matches the vendor baseline the builder view overrides were vendored from', function (string $vendorPath, string $expectedHash) {
    $absolute = dirname(__DIR__, 2).'/'.$vendorPath;

    expect(File::exists($absolute))->toBeTrue("Vendor view {$vendorPath} is missing — did the Filament view move?");

    expect(hash_file('sha256', $absolute))->toBe(
        $expectedHash,
        "Filament changed {$vendorPath} since baseline ".FILAMENT_VIEW_BASELINE.'. '
            .'Re-apply the vendor changes to the override in resources/overrides/filament-forms/ '
            .'(keep the cms:start/cms:end blocks), then update the hash in this test.',
    );
})->with('overridden vendor views');
