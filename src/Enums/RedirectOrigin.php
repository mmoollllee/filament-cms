<?php

namespace Mmoollllee\Cms\Enums;

use Mmoollllee\Cms\Http\Controllers\Frontend\ResolveNotFoundController;
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
 * - Rename: written by the model whenever a page's path moves, so the address it left
 *   keeps working. Permanent (301) like Manual — the page really did move — but kept
 *   apart from it: a rename may repoint its own earlier row, and must never touch one an
 *   admin curated. The list says where the row came from, and the editor taking that
 *   address back is told what they are removing.
 */
enum RedirectOrigin: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
    case Suggested = 'suggested';
    case Rename = 'rename';

    /**
     * Whether a person decided this redirect, as opposed to the fuzzy 404 resolver.
     *
     * Read where machine-written rows may be replaced but human ones must not
     * ({@see ResolveNotFoundController}) — a
     * predicate rather than a list of cases, so adding an origin cannot silently reopen
     * that hole the way Rename did.
     */
    public function isHumanAuthored(): bool
    {
        return match ($this) {
            self::Manual, self::Rename => true,
            self::Automatic, self::Suggested => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuell',
            self::Automatic => 'Automatische Weiterleitung',
            self::Suggested => 'Automatischer Vorschlag',
            self::Rename => 'Beim Umbenennen angelegt',
        };
    }

    /** Filament badge color for this origin. */
    public function color(): string
    {
        return match ($this) {
            self::Manual => 'success',
            self::Automatic => 'info',
            self::Suggested => 'warning',
            self::Rename => 'success',
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
