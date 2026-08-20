<?php

namespace Mmoollllee\Cms\Enums;

enum TenantUserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Editor => 'Editor',
        };
    }

    /** Filament badge color, so a role reads the same in every table it appears in. */
    public function color(): string
    {
        return match ($this) {
            self::Admin => 'warning',
            self::Editor => 'gray',
        };
    }

    /** What each role may do, shown as the helper text of the role select. */
    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Darf zusätzlich Benutzer verwalten und einladen.',
            self::Editor => 'Darf Inhalte, Medien und Seiten-Einstellungen bearbeiten.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
