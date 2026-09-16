<?php

namespace Mmoollllee\Cms\Filament\Resources\Users\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Mmoollllee\Cms\Concerns\ResolvesPanelTenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Filament\Actions\MembershipActions;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;
use Mmoollllee\FilamentTenantAccess\Contracts\TenantOwner;
use Mmoollllee\FilamentTenantAccess\Filament\Concerns\InteractsWithTenantAccessTable;

/**
 * Everyone with access to this site — members AND people who have been invited
 * but have not accepted yet, in one list.
 *
 * The list, its rows and the access actions are filament-tenant-access'
 * ({@see InteractsWithTenantAccessTable}) — the same access list as every other
 * application built on it. This page adds what belongs to the CMS: the list IS
 * the users resource, so a member row also links to the account and — for a
 * superadmin — deletes it.
 *
 * Three ways in, as page header actions:
 * - **Mitglied einladen** — the normal route. A signed link goes out by mail;
 *   the recipient sets their own password and is attached on acceptance.
 * - **Direkt hinzufügen** — attach an EXISTING account without a mail.
 *   Superadmin only, because it presupposes a picker over the entire user
 *   directory, which a single site's admin has no business seeing.
 * - **Benutzer anlegen** — create an account with a password chosen by the admin.
 *
 * Removal is split the way the policy splits it: "Entfernen" ends a membership,
 * "Benutzerkonto löschen" ends the account and is superadmin-only
 * ({@see \Mmoollllee\Cms\Policies\UserPolicy}).
 */
class ListUsers extends ListRecords
{
    use InteractsWithTenantAccessTable;
    use ResolvesPanelTenant;

    protected static string $resource = UserResource::class;

    /**
     * Member accounts already looked up this request. Every member row asks for
     * its account several times — edit, role change, removal, deletion each
     * check a policy against it — so without this a row costs a query per action.
     * Private, so Livewire neither serializes nor accepts it from the client.
     *
     * @var array<int|string, User|null>
     */
    private array $memberCache = [];

    public function table(Table $table): Table
    {
        return $this->configureTenantAccessTable($table);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->makeInviteAction(),
            $this->makeAssignDirectlyAction(),
            CreateAction::make()
                ->label('Benutzer anlegen')
                ->color('gray'),
        ];
    }

    protected function getAccessTenant(): (Model&TenantOwner)|null
    {
        $tenant = $this->currentTenant();

        return $tenant instanceof Model ? $tenant : null;
    }

    /** A site can have more editors than fit a glance. */
    protected function isTenantAccessSearchable(): bool
    {
        return true;
    }

    /**
     * The package's row-level rule (never your own row) plus the CMS's: a site
     * admin cannot demote or detach a superadmin. {@see UserPolicy::detach()}
     * holds both.
     *
     * @param  array<string, mixed>  $record
     */
    protected function canManageAccessMember(array $record): bool
    {
        $user = $this->memberFor($record);

        return $user !== null && Gate::allows('detach', $user);
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getTenantAccessRecordActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('edit')
                    ->label('Bearbeiten')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (array $record): bool => $record['type'] === 'member'
                        && Gate::allows('update', $this->memberFor($record)))
                    ->url(fn (array $record): string => static::getResource()::getUrl('edit', ['record' => $record['user_id']])),
                $this->makeChangeRoleAction(),
                $this->makeResendAction(),
                $this->makeCancelInvitationAction(),
                $this->makeRemoveAction()
                    ->modalDescription(fn (array $record): string => $record['name']
                        .' verliert den Zugriff auf diese Seite. Das Benutzerkonto und alle anderen Seiten bleiben unberührt.'),
                MembershipActions::deleteAccountCopy(Action::make('delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (array $record): string => 'Das Konto von '.$record['name']
                        .' wird gelöscht — auch der Zugriff auf alle ANDEREN Seiten.')
                    ->authorize(fn (array $record): bool => $record['type'] === 'member'
                        && Gate::allows('delete', $this->memberFor($record)))
                    ->action(function (array $record): void {
                        $this->memberFor($record)?->delete();

                        Notification::make()
                            ->success()
                            ->title('Benutzerkonto gelöscht')
                            ->send();
                    }),
            ])->label('Aktionen'),
        ];
    }

    /**
     * The account behind a member row, looked up among this site's members so a
     * row key can never reach an account from elsewhere.
     *
     * @param  array<string, mixed>  $record
     */
    protected function memberFor(array $record): ?User
    {
        $id = $record['user_id'] ?? null;

        if ($id === null) {
            return null;
        }

        if (! array_key_exists($id, $this->memberCache)) {
            /** @var ?User $user */
            $user = $this->getAccessTenant()?->users()->whereKey($id)->first();

            $this->memberCache[$id] = $user;
        }

        return $this->memberCache[$id];
    }
}
