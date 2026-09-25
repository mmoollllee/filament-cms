<?php

namespace Mmoollllee\Cms\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Filament\Providers\BasePanelProvider;
use Mmoollllee\Cms\Filament\Support\UnsavedChanges;

/**
 * "Ungespeicherte Änderungen" for the content + fragment edit AND create pages.
 *
 * A manage link (<x-cms::manage-link> — "Fragment bearbeiten", "… verwalten")
 * leads away from the form; following it with unsaved changes would silently
 * drop them. The link compares the form against its last pristine moment
 * ({@see $draftSavedDataHash}) and, when they differ, opens
 * {@see confirmLeaveAction()} instead of navigating: save first, leave without
 * saving, or stay. What "save first" means is the page's call
 * ({@see persistBeforeLeaving()}) — a draft on a live page, a held-back record
 * on a create page.
 *
 * NOTE for pages: stamp the hash wherever the form matches what is stored —
 * rememberData() is the funnel both Filament page types run through (mount,
 * save, create).
 */
trait ConfirmsLeaving
{
    /**
     * Hash of the form data at its last pristine moment (fill, save, stash).
     * The browser compares it against the live form data — same formula as
     * Filament's unsaved-changes alert ({@see UnsavedChanges}) — so the draft
     * buttons and manage links know whether there is anything to lose.
     * Maintained independently of the panel's optional unsavedChangesAlerts().
     */
    #[Locked]
    public ?string $draftSavedDataHash = null;

    /**
     * Persist the form before leaving. True once it is written; false when a
     * write guard refused or a save hook halted — the form then keeps its
     * changes and the editor stays. A validation failure throws.
     */
    abstract protected function persistBeforeLeaving(): bool;

    /** The confirmation's question: what saving first would do. */
    abstract protected function leaveQuestion(): string;

    /** The label of the confirmation's save button. */
    abstract protected function leaveSaveLabel(): string;

    /** The current form data becomes the pristine baseline. */
    protected function stampPristineFormHash(): void
    {
        $this->draftSavedDataHash = UnsavedChanges::hash($this->data);
    }

    /** Whether the form still matches its last pristine moment. */
    protected function formIsPristine(): bool
    {
        return $this->draftSavedDataHash !== null
            && hash_equals($this->draftSavedDataHash, UnsavedChanges::hash($this->data));
    }

    /**
     * The confirmation a manage link opens on a changed form, with the link's
     * URL as argument. Protected like every action factory on these pages:
     * Filament resolves it by name, Livewire must not expose it.
     */
    protected function confirmLeaveAction(): Action
    {
        return Action::make('confirmLeave')
            ->requiresConfirmation()
            // Three buttons side by side need more room than a plain confirmation.
            ->modalWidth(Width::Large)
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalIconColor('warning')
            ->modalHeading('Ungespeicherte Änderungen')
            ->modalDescription(fn (): string => $this->leaveQuestion())
            ->modalSubmitActionLabel(fn (): string => $this->leaveSaveLabel())
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('leaveWithoutSaving', arguments: ['save' => false])
                    ->label('Nicht speichern')
                    ->color('gray'),
            ])
            ->mountUsing(function (array $arguments, Action $action): void {
                $url = $this->safeLeaveUrl($arguments['url'] ?? null);

                // Only a click on a manage link asks — never a crafted `?action=`
                // link, and never for a target outside the panel.
                if ($url === null || $this->leaveMountedFromUrl()) {
                    $action->cancel();
                }

                // The browser judged the form changed, the server (which now has
                // the same data) finds it clean — nothing to ask, just follow it.
                if ($this->formIsPristine()) {
                    $this->redirect($url);

                    $action->cancel();
                }
            })
            ->action(function (array $arguments, Action $action): void {
                $url = $this->safeLeaveUrl($arguments['url'] ?? null);

                if ($url === null) {
                    $action->cancel();
                }

                if ($arguments['save'] ?? true) {
                    try {
                        $saved = $this->persistBeforeLeaving();
                    } catch (ValidationException $exception) {
                        // The errors belong to the form BEHIND the modal: close it so they
                        // show on the fields, and stay on the page.
                        $this->unmountAction();

                        throw $exception;
                    }

                    // Refused or halted — whatever stopped it has said why. Stay.
                    if (! $saved) {
                        $action->cancel();
                    }
                }

                $this->redirect($url);
            });
    }

    /**
     * The link target, when it is one to send an editor to: a path on this
     * host, or an http(s) URL on this host or on the panel domain of a tenant
     * the user may enter (an inherited fragment is edited there). The URL comes
     * from the browser, so anything else — another host, `//host` or `/\host`,
     * credentials in the URL, a javascript: URL — is refused.
     */
    protected function safeLeaveUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || preg_match('/[\s\\\\\x00-\x1f\x7f]/', $url)) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || blank($parts['host'] ?? null)) {
            return null;
        }

        return $this->isLeavablePanelHost(strtolower($parts['host'])) ? $url : null;
    }

    /**
     * This host, or the panel domain of a tenant the user may enter — a tenant's
     * panel runs on its `primary_domain` ({@see BasePanelProvider}).
     */
    private function isLeavablePanelHost(string $host): bool
    {
        if ($host === strtolower(request()->getHost())) {
            return true;
        }

        $tenant = Cms::tenantModel()::query()->where('primary_domain', $host)->first();
        $user = Filament::auth()->user();

        return $tenant instanceof Model && $user instanceof HasTenants && $user->canAccessTenant($tenant);
    }

    /** Whether Filament mounted the running action from the page URL (`?action=`). */
    private function leaveMountedFromUrl(): bool
    {
        $mountedAction = $this->mountedActions[array_key_last($this->mountedActions)] ?? [];

        return (bool) ($mountedAction['context']['mountedFromUrl'] ?? false);
    }
}
