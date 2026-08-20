<?php

namespace Mmoollllee\Cms\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Mail\TenantInvitationMail;
use Mmoollllee\Cms\Models\TenantInvitation;

/**
 * Creates and re-sends tenant invitations.
 *
 * Authorization happens BEFORE the call (the panel actions check the policy);
 * this class only builds the invitation and puts the mail on its way.
 */
class TenantInvitations
{
    /**
     * Invite an address to a tenant, or refresh the invitation it already has.
     *
     * Re-using the row is what the (tenant, email) unique index requires, and
     * it is also the behavior wanted: someone re-invited after leaving gets a
     * fresh token and expiry rather than a duplicate entry in the access list.
     */
    public function invite(Tenant $tenant, string $email, TenantUserRole $role, ?User $invitedBy = null): TenantInvitation
    {
        return DB::transaction(function () use ($tenant, $email, $role, $invitedBy): TenantInvitation {
            $invitation = TenantInvitation::query()->firstOrNew([
                'tenant_id' => $tenant->getKey(),
                'email' => trim($email),
            ]);

            $invitation->forceFill([
                'role' => $role->value,
                'token' => TenantInvitation::generateToken(),
                'expires_at' => TenantInvitation::defaultExpiry(),
                'accepted_at' => null,
                'invited_by_user_id' => $invitedBy?->getAuthIdentifier(),
            ])->save();

            $this->dispatchInvitationMail($invitation);

            return $invitation;
        });
    }

    /** Fresh token, fresh expiry, same recipient — the "Neu senden" action. */
    public function resend(TenantInvitation $invitation, ?User $by = null): void
    {
        DB::transaction(function () use ($invitation, $by): void {
            $invitation->forceFill([
                'token' => TenantInvitation::generateToken(),
                'expires_at' => TenantInvitation::defaultExpiry(),
                'accepted_at' => null,
                'invited_by_user_id' => $by?->getAuthIdentifier() ?? $invitation->invited_by_user_id,
            ])->save();

            $this->dispatchInvitationMail($invitation);
        });
    }

    protected function dispatchInvitationMail(TenantInvitation $invitation): void
    {
        Mail::to($invitation->email)->send(new TenantInvitationMail($invitation));
    }
}
