<?php

namespace Mmoollllee\Cms\Enums;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Mmoollllee\Cms\Policies\Concerns\AuthorizesTenantAdmins;
use Mmoollllee\FilamentTenantAccess\Concerns\IsTenantRole;
use Mmoollllee\FilamentTenantAccess\Contracts\TenantRole;

/**
 * A member's role within one site. Speaks the filament-tenant-access role
 * contract, so the shared access list, role select and invitation flow render
 * it the way they render the roles of every other application. `options()`
 * comes with the contract's trait.
 */
enum TenantUserRole: string implements TenantRole
{
    use IsTenantRole;

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

    public function icon(): BackedEnum
    {
        return match ($this) {
            self::Admin => Heroicon::OutlinedShieldCheck,
            self::Editor => Heroicon::OutlinedPencilSquare,
        };
    }

    /**
     * The CMS authorizes by role ({@see AuthorizesTenantAdmins}),
     * not by permission string; these mirror that rule for code that asks the
     * shared contract instead.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => ['*'],
            self::Editor => ['tenant:update', '*:view', '*:create', '*:update'],
        };
    }
}
