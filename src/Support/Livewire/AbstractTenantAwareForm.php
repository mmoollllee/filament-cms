<?php

namespace Mmoollllee\Cms\Support\Livewire;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Base for public, tenant-aware Livewire forms (contact, job application, …).
 *
 * Centralises the spam/abuse scaffolding every public form repeats — a honeypot field,
 * the current-tenant resolver, contact-recipient resolution and rate limiting — so a
 * concrete form only declares its own fields, validation rules and submit/mail logic.
 * Pair with {@see Concerns\WithSpamQuiz} for a rotating, tenant-defined security question.
 */
abstract class AbstractTenantAwareForm extends Component
{
    /** Set once a submission is accepted (or silently swallowed as spam). */
    public bool $submitted = false;

    /** Honeypot — must stay empty; bots fill it. */
    public string $website = '';

    abstract public function submit(): void;

    protected function currentTenant(): ?Tenant
    {
        return app(CurrentTenant::class)->get();
    }

    /**
     * Silently accept (without sending) when the honeypot was filled. Returns true so
     * the caller can short-circuit submit().
     */
    protected function trippedHoneypot(): bool
    {
        if (filled($this->website)) {
            $this->submitted = true;

            return true;
        }

        return false;
    }

    protected function rateLimitKey(string $prefix): string
    {
        $tenantKey = $this->currentTenant()?->getKey() ?? 'global';

        return $prefix.':'.$tenantKey.':'.request()->ip();
    }

    /**
     * @throws ValidationException when the limiter is exhausted.
     */
    protected function ensureWithinRateLimit(string $key, string $field, string $message, int $maxAttempts = 5): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    protected function hitRateLimit(string $key, int $decaySeconds = 60): void
    {
        RateLimiter::hit($key, $decaySeconds);
    }

    /**
     * Resolve the operator recipient: an explicit per-page override, else the tenant's
     * configured contact email.
     */
    protected function resolveContactRecipient(?string $override): string
    {
        if (filled($override)) {
            return trim($override);
        }

        return trim((string) ($this->currentTenant()?->resolvedSiteSetting('contact_email') ?? ''));
    }
}
