<?php

namespace Mmoollllee\Cms\Filament\Support;

/**
 * Whether a form page holds changes it has not saved yet — the one place that
 * mirrors Filament's unsaved-changes formula: `HasUnsavedDataChangesAlert` on
 * the server, `unsaved-changes-alert.js` in the browser. The two halves must
 * hash identically, or a clean form would count as changed (or the reverse);
 * DraftHashDriftGuardTest pins both against the vendor copies.
 *
 * Used by the draft buttons ("Entwurf speichern" disables itself on a clean
 * form) and by <x-cms::manage-link>, which asks before leaving a changed one.
 */
final class UnsavedChanges
{
    /**
     * A form's data hashed the way Filament's unsaved-changes alert stamps it.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function hash(?array $data): string
    {
        return md5((string) str(json_encode($data, JSON_UNESCAPED_UNICODE))->replace('\\', ''));
    }

    /**
     * JS expression: whether the form data (`$json`, a JSON string expression)
     * still hashes to the page's pristine stamp (`$hash`).
     */
    public static function pristineJs(
        string $json = 'JSON.stringify($wire.data)',
        string $hash = '$wire.draftSavedDataHash',
    ): string {
        return "window.jsMd5({$json}.replace(/\\\\/g, '')) === {$hash}";
    }
}
