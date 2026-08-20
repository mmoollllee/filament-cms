<?php

namespace Mmoollllee\Cms\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * The wording and framing of the two ways to take somebody's access away,
 * shared by the users list and the user edit page.
 *
 * They are genuinely different actions — one acts on a table row, the other on
 * the page's record — so what is shared here is what a reader compares: the
 * label, the icon, the danger colour and the modal copy that explains the
 * difference between ending a MEMBERSHIP and ending an ACCOUNT. Callers add
 * their own `visible()` and `action()`, and may sharpen the description when
 * they know whose access it is.
 *
 * @see \Mmoollllee\Cms\Policies\UserPolicy for the rule the two labels describe
 */
class MembershipActions
{
    public static function detachFromTenant(): Action
    {
        return Action::make('detachFromTenant')
            ->label('Aus dieser Seite entfernen')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Zugriff entziehen?')
            ->modalDescription('Das Benutzerkonto und alle anderen Seiten bleiben unberührt.')
            ->modalSubmitActionLabel('Entfernen');
    }

    public static function deleteAccountCopy(Action $action): Action
    {
        return $action
            ->label('Benutzerkonto löschen')
            ->modalHeading('Benutzerkonto endgültig löschen?')
            ->modalDescription('Das Konto wird gelöscht — auch der Zugriff auf alle ANDEREN Seiten.')
            ->modalSubmitActionLabel('Löschen');
    }
}
