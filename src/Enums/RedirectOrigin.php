<?php

namespace Mmoollllee\Cms\Enums;

use Mmoollllee\Cms\Models\Redirect;

/**
 * Provenance of a {@see Redirect} — resolves the spec's
 * "Automatischer Vorschlag" vs. "Automatische Weiterleitung" into distinct states:
 *
 * - Manual: admin-created/-confirmed. The canonical, permanent (301) redirect.
 * - Automatic: created by the runtime auto-resolver at very-high confidence. Active,
 *   temporary (302) until an admin edits it (which promotes it to Manual). This is the
 *   "Automatische Weiterleitung".
 * - Suggested: created at medium confidence. Inactive; shown to the visitor as a
 *   "Meinten Sie?" hint and surfaced to the admin for review. This is the
 *   "Automatischer Vorschlag".
 */
enum RedirectOrigin: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
    case Suggested = 'suggested';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuell',
            self::Automatic => 'Automatische Weiterleitung',
            self::Suggested => 'Automatischer Vorschlag',
        };
    }

    /** Filament badge color for this origin. */
    public function color(): string
    {
        return match ($this) {
            self::Manual => 'success',
            self::Automatic => 'info',
            self::Suggested => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
