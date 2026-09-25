<?php

namespace Mmoollllee\Cms\Support\Preview;

use Illuminate\Http\Request;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\Content\HasPublishingStatus;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Contracts\User;

/**
 * Request-scoped "Vorschau" state: whether the current frontend request renders
 * stashed drafts ({@see \Mmoollllee\Cms\Concerns\HasDraft}) instead of the
 * applied content.
 *
 * Entered/left via the `?preview=1` / `?preview=0` query param and remembered
 * in the session PER TENANT (sessions can span tenants when the cookie domain
 * covers subdomain sites), so follow-up navigation (and the onepager's XHR
 * section loads) stays in preview without carrying the param. Only superadmins
 * and members of the resolved tenant can activate it — for everyone else the
 * flag is inert and the live site renders.
 *
 * The overlay must NEVER be active while the admin panel talks to the server:
 * ResolveTenantFromHost also runs for panel routes (Filament persistent
 * middleware) and — in apps that append it to the `web` group — for Livewire's
 * /livewire/update endpoint, where an overlaid record would corrupt panel
 * write flows. activateFromRequest() therefore hard-skips panel and Livewire
 * URIs. Cache builders that produce guest-facing data wrap themselves in
 * {@see bypass()} for the same reason.
 *
 * The FOCUS names the record a "Vorschau" was opened from when it has no page of
 * its own (a notice, a weekly offer, a team member): `?preview_focus=ID`. Lists
 * show every entry in a preview anyway; a template that shows ONE entry of
 * several orders the focused one first
 * ({@see HasPublishingStatus::scopePreviewFocusFirst()}), so the "Vorschau" of an
 * older or a scheduled offer shows that offer. Request-scoped, never sticky.
 */
class PreviewMode
{
    public const SESSION_KEY = 'cms.preview';

    public const QUERY_PARAM = 'preview';

    public const FOCUS_PARAM = 'preview_focus';

    protected bool $active = false;

    protected ?int $focusedContentId = null;

    public function activateFromRequest(Request $request, Tenant $tenant): void
    {
        $this->active = false;
        $this->focusedContentId = null;

        if (! $request->hasSession() || $this->isPanelOrLivewireRequest($request)) {
            return;
        }

        $session = $request->session();
        $sessionKey = $this->sessionKey($tenant);

        // Reading the QUERY STRING only is deliberate: $request->boolean()
        // would also honor POST bodies, letting a form field named "preview"
        // toggle the mode.
        $hasParam = $request->query->has(self::QUERY_PARAM);

        // Fast path: nothing to do (and no membership query) unless the mode
        // is being toggled or is already sticky for this tenant.
        if (! $hasParam && ! $session->has($sessionKey)) {
            return;
        }

        $user = $request->user();

        if (! $user instanceof User || ! ($user->isSuperadmin() || $tenant->hasUser($user))) {
            return;
        }

        if ($hasParam) {
            filter_var($request->query(self::QUERY_PARAM), FILTER_VALIDATE_BOOL)
                ? $session->put($sessionKey, true)
                : $session->forget($sessionKey);
        }

        $this->active = (bool) $session->get($sessionKey, false);

        if ($this->active) {
            $focus = filter_var($request->query(self::FOCUS_PARAM), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            $this->focusedContentId = $focus === false ? null : $focus;
        }
    }

    public function active(): bool
    {
        return $this->active;
    }

    /**
     * The content record the preview was opened for, if it named one. Null
     * whenever the preview is inactive — including inside {@see bypass()}, so a
     * guest-facing cache can never pick up the focused record.
     */
    public function focusedContentId(): ?int
    {
        return $this->active ? $this->focusedContentId : null;
    }

    /** Force-activate without a request cycle (tests, artisan tinkering). */
    public function activate(): void
    {
        $this->active = true;
    }

    /** Focus a record without a request cycle (tests, artisan tinkering). */
    public function focus(?int $contentId): void
    {
        $this->focusedContentId = $contentId;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    /**
     * Run $callback with the overlay suspended — for builders of guest-facing
     * persistent state (sitemap, redirect map, menu links, 404 candidates,
     * section caches) that must never freeze draft data.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function bypass(callable $callback): mixed
    {
        $previous = $this->active;
        $this->active = false;

        try {
            return $callback();
        } finally {
            $this->active = $previous;
        }
    }

    protected function sessionKey(Tenant $tenant): string
    {
        return self::SESSION_KEY.'.'.$tenant->getKey();
    }

    /**
     * Whether the request belongs to the admin panel or Livewire's component
     * endpoints — contexts in which the overlay must never activate.
     */
    protected function isPanelOrLivewireRequest(Request $request): bool
    {
        if ($request->is('livewire/*')) {
            return true;
        }

        $panelPath = trim(Cms::panelPath(), '/');

        return $panelPath !== '' && ($request->is($panelPath) || $request->is($panelPath.'/*'));
    }
}
