<?php

namespace Mmoollllee\Cms\Filament\Resources\Users\Pages;

use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Mmoollllee\Cms\Concerns\ResolvesPanelTenant;
use Mmoollllee\Cms\Filament\Actions\MembershipActions;
use Mmoollllee\Cms\Filament\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    use ResolvesPanelTenant;

    protected static string $resource = UserResource::class;

    /**
     * The same split as the list ({@see ListUsers}): ending a membership is a
     * tenant-admin act, ending the account is not.
     */
    protected function getHeaderActions(): array
    {
        return [
            MembershipActions::detachFromTenant()
                ->visible(fn (): bool => Gate::allows('detach', $this->getRecord()))
                ->action(function (): void {
                    $this->currentTenant()?->removeUser($this->getRecord());

                    Notification::make()
                        ->success()
                        ->title('Zugriff entzogen')
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
            MembershipActions::deleteAccountCopy(DeleteAction::make()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['role'] = $this->getRecord()->tenantRole($tenant)?->value;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $role = $data['role'] ?? null;
        unset($data['role'], $data['password_confirmation']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $record->update($data);

        if ($role !== null) {
            Filament::getTenant()->users()->updateExistingPivot($record->id, ['role' => $role]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
