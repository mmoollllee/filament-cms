<?php

namespace Mmoollllee\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;
use Mmoollllee\Cms\Enums\TenantUserRole;

/**
 * A pending offer of tenant membership, addressed by e-mail.
 *
 * The token IS the credential — it is the only thing the accept link carries —
 * so it is generated server-side, unique, and paired with an expiry that also
 * bounds the signed URL ({@see acceptUrl()}). Accepting attaches the user to
 * the tenant with the invited role and stamps `accepted_at`; the row survives
 * as the record of who let whom in.
 *
 * @property-read Tenant $tenant
 */
class TenantInvitation extends Model
{
    /**
     * Session key carrying a pending token from the accept redirect to the
     * registration page — the query param alone would be lost on a validation
     * repost of that form.
     */
    public const SESSION_TOKEN_KEY = 'cms_tenant_invitation_token';

    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token',
        'expires_at',
        'accepted_at',
        'invited_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => TenantUserRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->token ??= static::generateToken();
            $invitation->expires_at ??= static::defaultExpiry();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Cms::tenantModel());
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Cms::userModel(), 'invited_by_user_id');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * Attach the user to the tenant with the invited role and close the
     * invitation. Idempotent — a second accept is a no-op rather than a second
     * pivot row, because the accept link stays in the recipient's inbox.
     */
    public function accept(User $user): void
    {
        if ($this->isAccepted()) {
            return;
        }

        $this->tenant->addUser($user, $this->role ?? TenantUserRole::Editor);

        $this->forceFill(['accepted_at' => Carbon::now()])->save();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->where(fn (Builder $nested) => $nested
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', Carbon::now()));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at');
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
            'cms.tenant-invitations.accept',
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

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function defaultExpiry(): Carbon
    {
        return Carbon::now()->addDays((int) config('cms.invitations.expires_after_days', 14));
    }
}
