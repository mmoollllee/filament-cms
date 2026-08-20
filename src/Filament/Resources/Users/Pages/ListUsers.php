<?php

namespace Mmoollllee\Cms\Filament\Resources\Users\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\ResolvesPanelTenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Filament\Actions\MembershipActions;
use Mmoollllee\Cms\Filament\Forms\MembershipFields;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;
use Mmoollllee\Cms\Models\TenantInvitation;
use Mmoollllee\Cms\Support\Tenancy\TenantInvitations;

/**
 * Everyone with access to this site — members AND people who have been invited
 * but have not accepted yet.
 *
 * The two live in different tables (`tenant_user` and `tenant_invitations`) but
 * are one question for an admin ("who can get in here?"), so they are one list:
 * a pending invitation is access already granted, just not yet collected, and
 * putting it on a page of its own means the answer is only ever half visible.
 * That is why the table is built from `records()` rather than the resource's
 * Eloquent query — the rows come from two sources and are plain arrays.
 *
 * Three ways in:
 * - **Einladen** — the normal route. A signed link goes out by mail; the
 *   recipient sets their own password and is attached on acceptance.
 * - **Direkt zuweisen** — attach an EXISTING account without a mail. Superadmin
 *   only, because it presupposes a picker over the entire user directory, which
 *   a single site's admin has no business seeing.
 * - **Anlegen** — create an account with a password chosen by the admin.
 *
 * Removal is split the way the policy splits it: "Aus dieser Seite entfernen"
 * ends a membership, "Benutzerkonto löschen" ends the account and is
 * superadmin-only ({@see \Mmoollllee\Cms\Policies\UserPolicy}).
 */
class ListUsers extends ListRecords
{
    use ResolvesPanelTenant;

    protected static string $resource = UserResource::class;

    private const TYPE_MEMBER = 'member';

    private const TYPE_INVITATION = 'invitation';

    /**
     * The member models behind the rows, kept so the row actions can reach a
     * user without querying for one the table already loaded — every action's
     * visible() closure asks for it, three times per row.
     *
     * @var Collection<int|string, User>|null
     */
    private ?Collection $memberModels = null;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search): Collection => $this->accessRows($search))
            // ListRecords::makeTable() wires a row click and a row URL that both
            // expect an Eloquent Model; these rows are arrays, so the defaults
            // fatal on render. The row's own "Bearbeiten" action carries the link.
            ->recordAction(null)
            ->recordUrl(null)
            // A site has a handful of editors; paging two merged sources would
            // cost more than it saves.
            ->paginated(false)
            ->emptyStateHeading('Niemand hat Zugriff auf diese Seite')
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->columns([
                // One column for the person: name over address. It is the only
                // searchable one, and matchesSearch() covers BOTH halves of what
                // it shows — the search box exists because of this column.
                TextColumn::make('name')
                    ->label('Person')
                    ->description(fn (array $record): string => $record['email'])
                    ->searchable(),
                TextColumn::make('role_label')
                    ->label('Rolle')
                    ->badge()
                    ->color(fn (array $record): string => $record['role_color']),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (array $record): string => $record['status_color']),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit')
                        ->label('Bearbeiten')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->visible(fn (array $record): bool => $record['type'] === self::TYPE_MEMBER
                            && Gate::allows('update', $this->userFor($record)))
                        ->url(fn (array $record): string => static::getResource()::getUrl('edit', ['record' => $record['user_id']])),
                    Action::make('resend')
                        ->label('Einladung neu senden')
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->requiresConfirmation()
                        ->modalDescription('Es wird ein neuer Link mit frischer Laufzeit verschickt; der alte verfällt sofort.')
                        ->visible(fn (array $record): bool => $record['type'] === self::TYPE_INVITATION
                            && Gate::allows('update', $this->invitationFor($record)))
                        ->action(function (array $record): void {
                            $invitation = $this->invitationFor($record);

                            if ($invitation === null) {
                                return;
                            }

                            app(TenantInvitations::class)->resend($invitation, Filament::auth()->user());

                            Notification::make()
                                ->success()
                                ->title('Einladung erneut verschickt')
                                ->body($invitation->email)
                                ->send();
                        }),
                    Action::make('cancelInvitation')
                        ->label('Einladung zurückziehen')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Einladung zurückziehen?')
                        ->modalDescription(fn (array $record): string => 'Der Link in der Mail an '.$record['email'].' funktioniert danach nicht mehr.')
                        ->visible(fn (array $record): bool => $record['type'] === self::TYPE_INVITATION
                            && Gate::allows('delete', $this->invitationFor($record)))
                        ->action(function (array $record): void {
                            $this->invitationFor($record)?->delete();

                            Notification::make()
                                ->success()
                                ->title('Einladung zurückgezogen')
                                ->send();
                        }),
                    MembershipActions::detachFromTenant()
                        // Sharpened: here we know whose access it is.
                        ->modalDescription(fn (array $record): string => $record['name']
                            .' verliert den Zugriff auf diese Seite. Das Benutzerkonto und alle anderen Seiten bleiben unberührt.')
                        ->visible(fn (array $record): bool => $record['type'] === self::TYPE_MEMBER
                            && Gate::allows('detach', $this->userFor($record)))
                        ->action(function (array $record): void {
                            $user = $this->userFor($record);

                            if ($user === null) {
                                return;
                            }

                            $this->currentTenant()?->removeUser($user);

                            Notification::make()
                                ->success()
                                ->title('Zugriff entzogen')
                                ->body($record['name'].' ist kein Mitglied dieser Seite mehr.')
                                ->send();
                        }),
                    MembershipActions::deleteAccountCopy(Action::make('delete'))
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription(fn (array $record): string => 'Das Konto von '.$record['name']
                            .' wird gelöscht — auch der Zugriff auf alle ANDEREN Seiten.')
                        ->visible(fn (array $record): bool => $record['type'] === self::TYPE_MEMBER
                            && Gate::allows('delete', $this->userFor($record)))
                        ->action(function (array $record): void {
                            $this->userFor($record)?->delete();

                            Notification::make()
                                ->success()
                                ->title('Benutzerkonto gelöscht')
                                ->send();
                        }),
                ])->label('Aktionen'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->inviteAction(),
            $this->assignExistingAction(),
            CreateAction::make()
                ->label('Benutzer anlegen')
                ->color('gray'),
        ];
    }

    /**
     * Send a signed invitation. Guarded twice against inviting someone who is
     * already in: once live while typing (a hint, so the admin can correct the
     * address) and once on submit (the check that counts).
     */
    protected function inviteAction(): Action
    {
        return Action::make('invite')
            ->label('Benutzer einladen')
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalHeading('Benutzer einladen')
            ->modalDescription('Es geht eine E-Mail mit einem signierten Annahme-Link raus. Die Person vergibt ihr Passwort selbst und wird beim Annehmen dieser Seite zugewiesen.')
            ->modalSubmitActionLabel('Einladung senden')
            ->visible(fn (): bool => Gate::allows('create', Cms::userModel()))
            ->schema([
                TextInput::make('email')
                    ->label('E-Mail-Adresse')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state): void {
                        if (blank($state) || ! $this->currentTenant()?->hasUserWithEmail($state)) {
                            return;
                        }

                        Notification::make()
                            ->warning()
                            ->title('Bereits Mitglied')
                            ->body($state.' hat schon Zugriff auf diese Seite.')
                            ->send();
                    }),
                MembershipFields::roleSelect(),
            ])
            ->action(function (array $data): void {
                $tenant = $this->currentTenant();

                if ($tenant === null) {
                    return;
                }

                if ($tenant->hasUserWithEmail($data['email'])) {
                    Notification::make()
                        ->warning()
                        ->title('Bereits Mitglied')
                        ->body($data['email'].' hat schon Zugriff auf diese Seite — es wurde nichts verschickt.')
                        ->send();

                    return;
                }

                app(TenantInvitations::class)->invite(
                    $tenant,
                    $data['email'],
                    TenantUserRole::from($data['role']),
                    Filament::auth()->user(),
                );

                Notification::make()
                    ->success()
                    ->title('Einladung verschickt')
                    ->body($data['email'].' wurde eingeladen.')
                    ->send();
            });
    }

    /** Attach existing accounts without a mail — superadmin only, see the class docblock. */
    protected function assignExistingAction(): Action
    {
        return Action::make('assignExisting')
            ->label('Direkt zuweisen')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->modalHeading('Bestehende Benutzer zuweisen')
            ->modalDescription('Die gewählten Konten erhalten sofort Zugriff — ohne Einladungsmail.')
            ->modalSubmitActionLabel('Zuweisen')
            ->visible(fn (): bool => Filament::auth()->user()?->isSuperadmin() ?? false)
            ->schema([
                Select::make('user_ids')
                    ->label('Benutzer')
                    ->multiple()
                    ->required()
                    // Searched in the database, not filtered in the browser: the
                    // pool is every account on the install, and rendering it as
                    // options would ship the whole directory on every open.
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->assignableUserOptions($search))
                    ->getOptionLabelsUsing(fn (array $values): array => $this->assignableUserOptions(ids: $values)),
                MembershipFields::roleSelect(),
            ])
            ->action(function (array $data): void {
                $tenant = $this->currentTenant();

                if ($tenant === null) {
                    return;
                }

                $role = TenantUserRole::from($data['role']);

                /** @var Collection<int, User> $users */
                $users = Cms::userModel()::query()->whereIn('id', $data['user_ids'])->get();

                $users->each(fn (User $user) => $tenant->addUser($user, $role));

                Notification::make()
                    ->success()
                    ->title($users->count() === 1 ? 'Benutzer zugewiesen' : $users->count().' Benutzer zugewiesen')
                    ->body($users->map(fn (User $user): string => $this->displayName($user))->join(', '))
                    ->send();
            });
    }

    /**
     * Members first (they are the answer to "who works on this site"), then the
     * invitations still outstanding.
     *
     * @return Collection<string, array<string, mixed>>
     */
    protected function accessRows(?string $search = null): Collection
    {
        $tenant = $this->currentTenant();

        if ($tenant === null) {
            return collect();
        }

        $this->memberModels = static::getResource()::getEloquentQuery()
            ->orderBy('name')
            ->get()
            ->keyBy(fn (User $user): int|string => $user->getAuthIdentifier());

        // One query for every member's role instead of one per member:
        // tenantRole() issues its own SELECT each call, even for a loaded
        // relation. Superadmins keep tenantRole()'s override — the pivot is the
        // authority only for everybody else.
        $roles = $tenant->users()->pluck('tenant_user.role', 'users.id');

        $members = $this->memberModels
            ->values()
            ->map(fn (User $user): array => $this->memberRow($user, $roles));

        $invitations = $tenant->tenantInvitations()
            ->open()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TenantInvitation $invitation): array => $this->invitationRow($invitation));

        // Keyed, not a list: Filament takes the COLLECTION key as the record key
        // for array rows, and the row actions have to address a member and an
        // invitation that happen to share a numeric id without colliding.
        return $members->concat($invitations)
            ->filter(fn (array $row): bool => $this->matchesSearch($row, $search))
            ->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function matchesSearch(array $row, ?string $search): bool
    {
        if (blank($search)) {
            return true;
        }

        return Str::contains($row['name'].' '.$row['email'], trim($search), ignoreCase: true);
    }

    /**
     * @param  Collection<int|string, string>  $roles  pivot role per user id
     * @return array<string, mixed>
     */
    protected function memberRow(User $user, Collection $roles): array
    {
        $role = $user->isSuperadmin()
            ? TenantUserRole::Admin
            : TenantUserRole::tryFrom((string) $roles->get($user->getAuthIdentifier()));

        return [
            'id' => self::TYPE_MEMBER.'-'.$user->getAuthIdentifier(),
            'type' => self::TYPE_MEMBER,
            'user_id' => $user->getAuthIdentifier(),
            'name' => $this->displayName($user),
            'email' => (string) $user->getAttribute('email'),
            'role_label' => $role?->label() ?? '—',
            'role_color' => $role?->color() ?? 'gray',
            'status_label' => 'Aktiv',
            'status_color' => 'success',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function invitationRow(TenantInvitation $invitation): array
    {
        $expired = $invitation->isExpired();

        return [
            'id' => self::TYPE_INVITATION.'-'.$invitation->getKey(),
            'type' => self::TYPE_INVITATION,
            'invitation_id' => $invitation->getKey(),
            // No account yet, so the address IS the person.
            'name' => $invitation->email,
            'email' => $invitation->email,
            'role_label' => $invitation->role?->label() ?? '—',
            'role_color' => $invitation->role?->color() ?? 'gray',
            // expires_at is nullable and both isExpired() and scopeOpen() read a
            // null as "never expires" — so the date is only part of the label
            // when there is one.
            'status_label' => match (true) {
                $expired => 'Einladung abgelaufen',
                $invitation->expires_at === null => 'Eingeladen',
                default => 'Eingeladen, gültig bis '.$invitation->expires_at->translatedFormat('d.m.Y'),
            },
            'status_color' => $expired ? 'danger' : 'info',
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function userFor(array $record): ?User
    {
        $id = $record['user_id'] ?? null;

        if ($id === null) {
            return null;
        }

        return $this->memberModels?->get($id) ?? Cms::userModel()::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function invitationFor(array $record): ?TenantInvitation
    {
        $id = $record['invitation_id'] ?? null;

        return $id === null ? null : TenantInvitation::query()->find($id);
    }

    /**
     * Accounts not yet on this site, as [id => "Name (email)"] — matching
     * $search, or restricted to $ids when re-labelling an existing selection.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int|string, string>
     */
    protected function assignableUserOptions(?string $search = null, array $ids = []): array
    {
        $tenant = $this->currentTenant();

        if ($tenant === null) {
            return [];
        }

        return Cms::userModel()::query()
            ->whereNotIn('id', $tenant->users()->pluck('users.id'))
            ->when(filled($search), fn ($query) => $query->where(
                fn ($nested) => $nested
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'),
            ))
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->getAuthIdentifier() => $this->displayName($user).' ('.$user->getAttribute('email').')',
            ])
            ->all();
    }

    protected function displayName(User $user): string
    {
        return (string) ($user->getAttribute('name') ?: $user->getAttribute('email'));
    }
}
