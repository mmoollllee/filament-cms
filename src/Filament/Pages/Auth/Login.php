<?php

declare(strict_types=1);

namespace Mmoollllee\Cms\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

final class Login extends BaseLogin
{
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
