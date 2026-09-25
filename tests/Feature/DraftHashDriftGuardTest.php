<?php

/*
 * The "Entwurf speichern" disabled-state and the manage links' leave question
 * couple to two Filament INTERNALS: the window.jsMd5 global (set by
 * filament/support's JS bundle) and the exact unsaved-changes hash formula,
 * mirrored in UnsavedChanges — hash() server-side, pristineJs() client-side.
 * Filament maintains its own pair atomically — our copy would drift silently
 * (buttons permanently disabled/enabled, no server error). Same convention as
 * FilamentViewOverrideDriftTest: fail loudly on the vendor change, with
 * instructions.
 */

use Mmoollllee\Cms\Filament\Support\UnsavedChanges;

// On failure: Filament stopped exposing window.jsMd5 — update
// UnsavedChanges::pristineJs() to the new global (and this test).
it('pins the vendor jsMd5 global the draft buttons depend on', function () {
    $supportBundle = file_get_contents(base_path('vendor/filament/support/resources/js/index.js'));

    expect($supportBundle)->toContain('window.jsMd5 = md5');
});

it('pins the vendor hash formula mirrored by the draft pristine tracking', function () {
    $clientFormula = file_get_contents(base_path('vendor/filament/filament/resources/js/unsaved-changes-alert.js'));

    // Client side: identical stringify+replace input into jsMd5.
    expect($clientFormula)->toContain("window.jsMd5(JSON.stringify(\$wire.data).replace(/\\\\/g, ''))");

    // Server side: identical md5 recipe in HasUnsavedDataChangesAlert.
    $vendorTrait = file_get_contents(base_path('vendor/filament/filament/src/Pages/Concerns/HasUnsavedDataChangesAlert.php'));

    expect($vendorTrait)->toContain("md5((string) str(json_encode(\$this->data, JSON_UNESCAPED_UNICODE))->replace('\\\\', ''))");

    // And our mirror still matches both halves verbatim.
    $ourMirror = file_get_contents((new ReflectionClass(UnsavedChanges::class))->getFileName());

    expect($ourMirror)->toContain("md5((string) str(json_encode(\$data, JSON_UNESCAPED_UNICODE))->replace('\\\\', ''))")
        ->and(UnsavedChanges::pristineJs())->toContain("window.jsMd5(JSON.stringify(\$wire.data).replace(/\\\\/g, ''))");
});
// On failure: Filament changed its unsaved-changes hash formula — mirror the
// new recipe in UnsavedChanges::hash() + pristineJs() (and this test).
