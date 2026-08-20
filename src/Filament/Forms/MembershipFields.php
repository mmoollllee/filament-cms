<?php

namespace Mmoollllee\Cms\Filament\Forms;

use Filament\Forms\Components\Select;
use Mmoollllee\Cms\Enums\TenantUserRole;

/**
 * The role select every membership surface shares — the invite modal, the
 * direct assignment, the "Rolle ändern" action and the user form. One
 * definition so a new role, or a changed description, reaches all of them.
 */
class MembershipFields
{
    public static function roleSelect(string $label = 'Rolle', ?TenantUserRole $default = TenantUserRole::Editor): Select
    {
        return Select::make('role')
            ->label($label)
            ->options(TenantUserRole::options())
            ->default($default?->value)
            ->selectablePlaceholder(false)
            ->required()
            ->live()
            ->helperText(fn (?string $state): ?string => TenantUserRole::tryFrom((string) $state)?->description());
    }
}
