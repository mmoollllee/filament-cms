<?php

namespace Mmoollllee\Cms\Http\Controllers;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Mail\TenantInvitationMail;
use Mmoollllee\Cms\Models\TenantInvitation;

/**
 * Accept endpoint for a tenant invitation, reached through the signed link in
 * {@see TenantInvitationMail}.
 *
 * Four ways in, one destination:
 *  - signed in with the invited address  → attach + straight into the panel
 *  - signed in as somebody else          → 403 naming both addresses
 *  - signed out, account exists          → login (the intended URL resumes here)
 *  - signed out, no account              → the invitation-only registration page
 */
class TenantInvitationController extends Controller
{
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = TenantInvitation::query()
            ->with(['tenant', 'invitedBy'])
            ->where('token', $token)
            ->first();

        abort_if($invitation === null || $invitation->tenant === null, 404, 'Einladung nicht gefunden.');

        if ($invitation->isAccepted()) {
            return $this->redirectToPanel($invitation, 'Diese Einladung wurde bereits angenommen.');
        }

        abort_if($invitation->isExpired(), 410, 'Diese Einladung ist abgelaufen. Bitte fordern Sie eine neue an.');

        $authUser = Filament::auth()->user();

        if ($authUser instanceof User) {
            abort_if(
                strcasecmp((string) $authUser->getAttribute('email'), $invitation->email) !== 0,
                403,
                'Diese Einladung wurde an '.$invitation->email.' gesendet, angemeldet sind Sie als '
                    .$authUser->getAttribute('email').'. Bitte melden Sie sich mit der eingeladenen Adresse an.',
            );

            $invitation->accept($authUser);

            Notification::make()
                ->success()
                ->title('Willkommen!')
                ->body('Sie wurden zu '.$invitation->tenant->displayName().' hinzugefügt.')
                ->send();

            return $this->redirectToPanel($invitation);
        }

        // Signed out: come back here after authenticating.
        session()->put('url.intended', $request->fullUrl());

        $panel = Filament::getDefaultPanel();

        if (Cms::userModel()::query()->where('email', $invitation->email)->exists()) {
            return redirect()->to($panel->getLoginUrl())
                ->with('status', 'Bitte melden Sie sich an, um die Einladung anzunehmen.');
        }

        $registerUrl = $panel->getRegistrationUrl();

        if (blank($registerUrl)) {
            return redirect()->to($panel->getLoginUrl());
        }

        // Session AND query param: the session survives a validation repost on
        // the registration form, the query param covers a first hit that has no
        // session yet (a click straight out of the mail client).
        session()->put(TenantInvitation::SESSION_TOKEN_KEY, $invitation->token);

        return redirect()->to(
            $registerUrl.(str_contains($registerUrl, '?') ? '&' : '?').'invitation_token='.urlencode($invitation->token)
        );
    }

    protected function redirectToPanel(TenantInvitation $invitation, ?string $flash = null): RedirectResponse
    {
        $redirect = redirect()->to(Filament::getDefaultPanel()->getUrl($invitation->tenant));

        return $flash === null ? $redirect : $redirect->with('status', $flash);
    }
}
