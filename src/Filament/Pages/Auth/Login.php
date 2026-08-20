<?php

declare(strict_types=1);

namespace Mmoollllee\Cms\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

final class Login extends BaseLogin
{
    /**
     * No "oder erstellen Sie ein Konto" line. The panel DOES register a
     * registration page, but only so an invited person without an account has
     * somewhere to land — it refuses every visit without a valid invitation
     * token ({@see Register}). Advertising it here offers a sign-up that always
     * bounces straight back to this screen.
     *
     * Suppressed at the SUBHEADING, not by hiding the register action: the
     * subheading is the only thing that renders it, and it emits the "oder"
     * prefix itself — hiding the action alone leaves that stray word behind.
     * The multi-factor subheading is a different message and stays.
     */
    public function getSubheading(): string|Htmlable|null
    {
        return filled($this->userUndertakingMultiFactorAuthentication)
            ? parent::getSubheading()
            : null;
    }

    public function mount(): void
    {
        parent::mount();

        // Local-dev convenience only: prefill the credentials from the
        // CMS_DEV_LOGIN_* env vars (null by default — nothing is prefilled).
        $devLogin = config('cms.dev_login');

        if (app()->isLocal() && filled($devLogin['email'] ?? null)) {
            $this->form->fill([
                'email' => $devLogin['email'],
                'password' => $devLogin['password'] ?? null,
                'remember' => true,
            ]);
        }
    }
}
