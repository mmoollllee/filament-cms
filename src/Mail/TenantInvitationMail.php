<?php

namespace Mmoollllee\Cms\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Mmoollllee\Cms\Models\TenantInvitation;

class TenantInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public TenantInvitation $invitation,
    ) {
        // Queue only after the surrounding transaction commits — otherwise a
        // worker can pick the job up before the row (or, on a resend, the new
        // token) is visible, and mail out a link that answers 404.
        $this->afterCommit();
    }

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
