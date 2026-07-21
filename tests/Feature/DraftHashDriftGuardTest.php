<?php

/*
 * The "Entwurf speichern" disabled-state couples to two Filament INTERNALS:
 * the window.jsMd5 global (set by filament/support's JS bundle) and the exact
 * unsaved-changes hash formula, mirrored server-side in
 * ManagesDrafts::rememberData() and client-side in the buttons' Alpine effect.
 * Filament maintains its own pair atomically — our copy would drift silently
 * (buttons permanently disabled/enabled, no server error). Same convention as
 * FilamentViewOverrideDriftTest: fail loudly on the vendor change, with
 * instructions.
 */

use Mmoollllee\Cms\Filament\Concerns\ManagesDrafts;

// On failure: Filament stopped exposing window.jsMd5 — update
// ManagesDrafts::draftPristineEffectJs() to the new global (and this test).
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

    // And our mirror still matches that recipe verbatim.
    $ourTrait = file_get_contents((new ReflectionClass(ManagesDrafts::class))->getFileName());

    expect($ourTrait)->toContain("md5((string) str(json_encode(\$this->data, JSON_UNESCAPED_UNICODE))->replace('\\\\', ''))");
});
// On failure: Filament changed its unsaved-changes hash formula — mirror the
// new recipe in ManagesDrafts::rememberData() + draftPristineEffectJs()
// (and this test).
