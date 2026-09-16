<?php

namespace Mmoollllee\Cms\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\FilamentTenantAccess\Models\TenantInvitation as BaseTenantInvitation;

/**
 * A pending offer of site membership — filament-tenant-access' invitation,
 * with the two things a host-routed CMS does differently: the role is cast to
 * the CMS enum, and the accept link is built on the site's own domain.
 *
 * Token, expiry, accept() and the scopes are the package's; see
 * {@see BaseTenantInvitation}.
 *
 * @property TenantUserRole|null $role
 */
class TenantInvitation extends BaseTenantInvitation
{
    protected $table = 'tenant_invitations';

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'role' => TenantUserRole::class,
        ];
    }

    /**
     * Signed accept URL, expiring with the invitation itself — so a leaked link
     * is refused by the signature check before any model state is consulted.
     *
     * Built on the TENANT's own domain, not on `app.url`. Every request is
     * routed by host (ResolveTenantFromHost 404s a host that belongs to no
     * tenant), and the invitation is to one specific site — so a link on the
     * app's default host would answer 404 on any install serving more than the
     * one tenant.
     *
     * The signature covers the PATH only (`absolute: false`, matched by
     * `signed:relative` on the route). Signing the absolute URL would mean
     * forcing the URL generator's root around the call — a global mutation with
     * no way to read back what it was, so the "restore" pins the generator for
     * the rest of the process — and would tie the link to one host, breaking a
     * tenant that is reachable under more than one. The token is the credential
     * here; which host it is presented on is not part of the grant.
     */
    public function acceptUrl(): string
    {
        $path = URL::signedRoute(
            'tenant-access.invitations.accept',
            ['token' => $this->token],
            $this->expires_at,
            absolute: false,
        );

        $domain = $this->tenant?->getAttribute('primary_domain');

        if (blank($domain)) {
            return URL::to($path);
        }

        return (str_starts_with(URL::to('/'), 'http://') ? 'http://' : 'https://').$domain.$path;
    }

    public static function defaultExpiry(): Carbon
    {
        return Carbon::now()->addDays((int) config('cms.invitations.expires_after_days', 14));
    }
}
