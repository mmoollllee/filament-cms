<?php

namespace Mmoollllee\Cms\Support\Livewire;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\Analytics\Umami;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Base for public, tenant-aware Livewire forms (contact, job application, …).
 *
 * Centralises the spam/abuse scaffolding every public form repeats — a honeypot field,
 * the current-tenant resolver, contact-recipient resolution and rate limiting — so a
 * concrete form only declares its own fields, validation rules and submit/mail logic.
 * Pair with {@see Concerns\WithSpamQuiz} for a rotating, tenant-defined security question.
 *
 * Analytics: name the form via {@see analyticsName()} and it reports a start and a
 * completion to Umami — see {@see trackConversion()} for the two halves and what
 * must never go in the payload.
 */
abstract class AbstractTenantAwareForm extends Component
{
    /** Set once a submission is accepted (or silently swallowed as spam). */
    public bool $submitted = false;

    /** Honeypot — must stay empty; bots fill it. */
    public string $website = '';

    abstract public function submit(): void;

    /**
     * Identifier this form is measured under, e.g. 'contact-form'. Two events
     * derive from it: '<name>-start' when a visitor first touches the form
     * (fired client-side by <x-filami::events />, wired up by
     * {@see analyticsAttributes()}), and '<name>-submit' on success. Having
     * both is what turns a submission count into a completion rate.
     *
     * Null — the default — measures nothing, so forms that predate this opt in
     * rather than start reporting an unnamed event after an upgrade.
     *
     * A property, not a method to override: the name has to be readable from
     * outside (tests, and analyticsAttributes() puts it in the markup anyway),
     * and a protected hook made every consumer declare the name twice — once
     * in a public constant for the tests, once in the override.
     */
    protected ?string $analyticsName = null;

    public function analyticsName(): ?string
    {
        return $this->analyticsName;
    }

    /**
     * Attributes for the <form> tag: `{!! $this->analyticsAttributes() !!}`.
     * Empty when the form is unnamed or filami is not installed, so the same
     * template works either way.
     */
    public function analyticsAttributes(): HtmlString
    {
        $name = $this->analyticsName();

        if (blank($name) || ! Umami::installed()) {
            return new HtmlString('');
        }

        return new HtmlString('data-filami-form="'.e($name).'"');
    }

    /**
     * Report a completed submission. Call it at the very end of submit(), once
     * the mail is actually out: a form that fails validation, trips the
     * honeypot or dies on a mailer error must not count as a conversion, or
     * the dashboard measures attempts instead of inquiries.
     *
     * PRIVACY: $data lands next to the pageview in Umami, which is not a place
     * for anything about the sender — no name, address, phone or message text.
     * Categories are what make it useful: which variant of the form, which
     * product the inquiry was about.
     *
     * @param  array<string, scalar|null>  $data
     */
    protected function trackConversion(array $data = []): void
    {
        $name = $this->analyticsName();

        if (blank($name) || ! Umami::installed()) {
            return;
        }

        // Blank values would show up in Umami as an empty property value,
        // which reads as a recorded answer rather than an absent one.
        $this->dispatch('filami-track', name: $name.'-submit', data: array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

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
     *
     * SECURITY: the override is trusted verbatim, so whatever the subclass passes
     * in MUST NOT be client-writable. Public Livewire properties are rewritable
     * from the browser snapshot, and these forms are public — so the property
     * holding the override needs #[Locked] (see App\Livewire\KontaktForm).
     * Without it, an anonymous visitor redirects Mail::to() and turns the site
     * into an open relay sending from its own verified MAIL_FROM_ADDRESS.
     */
    protected function resolveContactRecipient(?string $override): string
    {
        if (filled($override)) {
            return trim($override);
        }

        return trim((string) ($this->currentTenant()?->resolvedSiteSetting('contact_email') ?? ''));
    }
}
