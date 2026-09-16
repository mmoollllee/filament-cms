<?php

namespace Mmoollllee\Cms\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Mmoollllee\Cms\Models\TenantInvitation;
use Mmoollllee\FilamentTenantAccess\Mail\TenantInvitationMail as BaseTenantInvitationMail;

/**
 * The invitation mail in the site's own branding. Sending, queueing and the
 * after-commit guard are filament-tenant-access'; this only swaps the view,
 * and is wired in through `tenant-access.invitations.mailable`.
 *
 * @property TenantInvitation $invitation
 */
class TenantInvitationMail extends BaseTenantInvitationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Einladung zu '.$this->invitation->tenant->displayName(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'cms::mail.tenant-invitation',
            with: [
                'tenant' => $this->invitation->tenant,
                'invitedBy' => $this->invitation->invitedBy,
                'role' => $this->invitation->role,
                'acceptUrl' => $this->invitation->acceptUrl(),
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
