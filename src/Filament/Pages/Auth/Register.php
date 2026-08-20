<?php

namespace Mmoollllee\Cms\Filament\Pages\Auth;

use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mmoollllee\Cms\Models\TenantInvitation;
use SensitiveParameter;

/**
 * Invitation-only account creation.
 *
 * The panel registers this page so an invited person WITHOUT an account has
 * somewhere to land — not to open the panel to the public. Every entry point is
 * gated on a valid, unaccepted, unexpired invitation: no token, no page. The
 * address is taken from the invitation and cannot be edited, so registering
 * can only ever produce the account the invitation was addressed to.
 */
class Register extends BaseRegister
{
    /** Resolved in mount(); the id, not the token, so it never reaches the DOM. */
    public ?int $invitationId = null;

    public function mount(): void
    {
        // Before the invitation check, mirroring the parent: somebody already
        // signed in has no business on a registration page, and bouncing them
        // to the login screen (which would just forward them again) with an
        // "invitation only" flash is a worse answer than the panel.
        if (Filament::auth()->check()) {
            $this->redirect(Filament::getDefaultPanel()->getUrl(), navigate: false);

            return;
        }

        $invitation = $this->resolvePendingInvitation();

        if ($invitation === null) {
            session()->flash('status', 'Eine Registrierung ist nur über eine Einladung möglich.');

            $this->redirect(Filament::getDefaultPanel()->getLoginUrl(), navigate: false);

            return;
        }

        $this->invitationId = $invitation->getKey();
        session()->put(TenantInvitation::SESSION_TOKEN_KEY, $invitation->token);

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getInvitedEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    /**
     * The invited address, shown but not editable — `disabled()` keeps it out of
     * the request, `dehydrated()` keeps it in the form state, and
     * handleRegistration() reads the invitation rather than this value anyway.
     */
    protected function getInvitedEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/register.form.email.label'))
            ->email()
            ->required()
            ->default(fn (): ?string => $this->invitation()?->email)
            ->disabled()
            ->dehydrated()
            ->helperText('Die Einladung wurde an diese Adresse gesendet und kann hier nicht geändert werden.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $invitation = TenantInvitation::query()->findOrFail($this->invitationId);

            abort_if(
                $invitation->isAccepted() || $invitation->isExpired(),
                410,
                'Diese Einladung ist nicht mehr gültig.',
            );

            // Someone claimed the address between the accept redirect and this
            // submit. Creating a second account would hit the users unique
            // index; sending them to sign in is what the accept flow would have
            // done had the account existed a moment earlier.
            abort_if(
                $this->getUserModel()::query()->where('email', $invitation->email)->exists(),
                409,
                'Für diese Adresse besteht bereits ein Konto. Bitte melden Sie sich an und öffnen Sie den Einladungslink erneut.',
            );

            $user = $this->getUserModel()::create([
                'name' => $data['name'],
                // From the invitation, never from the request: the field is
                // disabled in the browser, which is a UI fact, not a guarantee.
                'email' => $invitation->email,
                'password' => $data['password'],
            ]);

            $invitation->accept($user);
            session()->forget(TenantInvitation::SESSION_TOKEN_KEY);

            return $user;
        });
    }

    /**
     * No redirect override here on purpose: Filament's RegistrationResponse ends
     * on `redirect()->intended()`, and the accept controller put the (still
     * signed, still valid) accept URL there before sending the visitor here. The
     * new account therefore returns through the accept endpoint, which finds the
     * invitation closed and forwards into the panel — one code path for the
     * "already accepted" case instead of two.
     */
    protected function invitation(): ?TenantInvitation
    {
        return $this->invitationId === null
            ? null
            : TenantInvitation::query()->find($this->invitationId);
    }

    /**
     * The invitation this visit is for: the query param the accept redirect
     * appends, else the session copy that survives a validation repost.
     */
    protected function resolvePendingInvitation(): ?TenantInvitation
    {
        $token = (string) (request()->query('invitation_token') ?? session(TenantInvitation::SESSION_TOKEN_KEY) ?? '');

        if ($token === '') {
            return null;
        }

        return TenantInvitation::query()
            ->pending()
            ->where('token', $token)
            ->first();
    }
}
