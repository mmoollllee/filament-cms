<?php

namespace Mmoollllee\Cms\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;
use Livewire\Attributes\Locked;
use Mmoollllee\Cms\Support\Preview\Drafts;
use Mmoollllee\Cms\Support\Preview\PreviewMode;

/**
 * Draft-aware save flow for the content + fragment edit pages.
 *
 * Replaces the single "Speichern" with the pair
 * - "Entwurf speichern" — stashes the validated form state into the record's
 *   `draft` column ({@see \Mmoollllee\Cms\Concerns\HasDraft}) without touching
 *   the served content, and
 * - "Änderungen anwenden" — the classic save (applies the form state), which
 *   also clears the stash (via the RecordUpdated listener wired in
 *   CmsServiceProvider — event-based so a subclass overriding afterSave()
 *   cannot silently disable it).
 * Both render in the form footer AND the page header; the delete action moves
 * from the header into the footer as an icon-only trash button. A "Vorschau"
 * header action stashes the current form state as the draft and THEN opens the
 * frontend with `?preview=1` ({@see PreviewMode}) — the preview always shows
 * the form's state. "Entwurf verwerfen" appears while a draft is pending.
 *
 * On a model without the HasDraft trait every draft element hides and the page
 * degrades to the classic save flow ({@see Drafts::supported()}).
 *
 * NOTE for subclasses: this trait implements mutateFormDataBeforeFill(),
 * getSubheading() and rememberData(). Overrides must call the parent/trait
 * implementation (or {@see mergeDraftIntoFormData()}) to keep drafts loading
 * and the "Entwurf speichern" disabled-state accurate.
 */
trait ManagesDrafts
{
    /**
     * Hash of the form data at its last pristine moment (fill, stash, apply).
     * The client compares it against the live form data (same formula as
     * Filament's unsaved-changes alert) to disable "Entwurf speichern" while
     * there is nothing to stash. Maintained independently of the panel's
     * optional unsavedChangesAlerts() feature, so the buttons work in every panel.
     */
    #[Locked]
    public ?string $draftSavedDataHash = null;

    /**
     * Unix timestamp of the draft revision THIS page loaded (null = none).
     * Applying only clears a stash that is not NEWER than this — a stale tab
     * must not silently destroy a draft someone saved in the meantime.
     */
    #[Locked]
    public ?int $loadedDraftSavedAt = null;

    // -------------------------------------------------------------------------
    //  Livewire methods
    // -------------------------------------------------------------------------

    /**
     * "Entwurf speichern": validate + dehydrate the form exactly like save(),
     * but stash the result instead of applying it.
     *
     * Returns true once the stash is written — the preview action's click
     * handler gates the preview tab on it (a validation failure never returns,
     * so the handler sees no `true` and keeps the tab closed).
     */
    public function saveDraft(): bool
    {
        if (! $this->draftsSupported()) {
            return false;
        }

        $this->authorizeAccess();

        $this->callHook('beforeValidate');

        // The afterValidate closure also keeps getState() from running
        // saveRelationships() — a bare getState() would LIVE-write any
        // relationship-bound fields, breaking the stash-only promise.
        // beforeSave/afterSave are deliberately NOT called: they belong to
        // applying, which still runs the full save() pipeline later.
        $data = $this->form->getState(afterValidate: function (): void {
            $this->callHook('afterValidate');
        });

        $data = $this->mutateFormDataBeforeSave($data);

        $record = $this->getRecord();

        $record->stashDraft($data);

        $this->loadedDraftSavedAt = $record->draftSavedAt()?->getTimestamp();

        // The stash captured the current form state — leaving the page now must
        // not warn about unsaved changes, and "Entwurf speichern" disables
        // again until the form diverges anew.
        $this->rememberData();

        Notification::make()
            ->success()
            ->title('Entwurf gespeichert')
            ->body('Die Änderungen sind gespeichert, aber noch nicht angewendet — sichtbar über die Vorschau.')
            ->send();

        return true;
    }

    /**
     * "Änderungen anwenden" ran (dispatched via Filament's RecordUpdated event,
     * see CmsServiceProvider): the applied state IS the draft this page had
     * loaded — drop the stash. A NEWER stash (saved from another tab/user
     * after this form loaded) is preserved and announced instead of silently
     * destroyed.
     */
    public function handleAppliedDraft(Model $record): void
    {
        if (! Drafts::pending($record)) {
            return;
        }

        $stashSavedAt = $record->draftSavedAt()?->getTimestamp();

        if ($stashSavedAt !== null && ($this->loadedDraftSavedAt === null || $stashSavedAt > $this->loadedDraftSavedAt)) {
            Notification::make()
                ->warning()
                ->title('Neuerer Entwurf blieb erhalten')
                ->body('Seit dem Laden dieses Formulars wurde ein neuerer Entwurf gespeichert — er wurde NICHT verworfen.')
                ->send();

            return;
        }

        $record->discardDraft();
    }

    // -------------------------------------------------------------------------
    //  Form fill
    // -------------------------------------------------------------------------

    /**
     * Editing continues on the latest state: a pending draft wins over the
     * applied columns when the form loads.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->mergeDraftIntoFormData(parent::mutateFormDataBeforeFill($data));
    }

    /**
     * Generic draft merge. Content pages extend this (via trait alias) to also
     * derive type-specific virtual fields from the draft — see ContentEditPage.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mergeDraftIntoFormData(array $data): array
    {
        $record = $this->getRecord();

        if (! Drafts::pending($record)) {
            $this->loadedDraftSavedAt = null;

            return $data;
        }

        $this->loadedDraftSavedAt = $record->draftSavedAt()?->getTimestamp();

        return array_replace($data, $record->draftData());
    }

    /**
     * Every pristine moment funnels through rememberData() — mount (via
     * Filament's trait mount hook, after fill), apply, saveDraft, the discard
     * refill and saveFormComponentOnly(). Stamp the draft hash here,
     * UNCONDITIONALLY: the parent is a no-op unless the panel enables
     * unsavedChangesAlerts(), but the draft buttons work in every panel.
     */
    protected function rememberData(): void
    {
        parent::rememberData();

        $this->draftSavedDataHash = md5((string) str(json_encode($this->data, JSON_UNESCAPED_UNICODE))->replace('\\', ''));
    }

    public function getSubheading(): string|Htmlable|null
    {
        $record = $this->getRecord();

        if (Drafts::pending($record)) {
            $savedAt = $record->draftSavedAt()?->format('d.m.Y H:i');

            return $savedAt !== null
                ? "Entwurf vom {$savedAt} Uhr geladen — noch nicht angewendet."
                : 'Entwurf geladen — noch nicht angewendet.';
        }

        return parent::getSubheading();
    }

    // -------------------------------------------------------------------------
    //  Actions
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        // Stable morph identity for EVERY header action: siblings toggle
        // visibility ("Entwurf verwerfen"), and without keys Livewire's morph
        // matches buttons positionally — they inherit each other's DOM state
        // (e.g. the preview button acquiring the draft button's disabled binding).
        return array_map(
            fn (Action $action): Action => $action->extraAttributes(
                ['wire:key' => 'cms-header-action-'.$action->getName()],
                merge: true,
            ),
            [
                $this->getDiscardDraftAction(),
                $this->getPreviewAction(),
                $this->getSaveDraftHeaderAction(),
                $this->getApplyHeaderAction(),
            ],
        );
    }

    /**
     * Footer: [Änderungen anwenden] [Entwurf speichern] [Abbrechen] [🗑].
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getSaveDraftFormAction(),
            $this->getCancelFormAction(),
            $this->getDeleteFormAction(),
        ];
    }

    /** The classic submit, relabeled: saving now means applying. */
    protected function getSaveFormAction(): Action
    {
        $action = parent::getSaveFormAction();

        return $this->draftsSupported()
            ? $action->label('Änderungen anwenden')
            : $action;
    }

    protected function getSaveDraftFormAction(): Action
    {
        return $this->makeSaveDraftAction('saveDraft')
            ->keyBindings(['mod+shift+s']);
    }

    protected function getSaveDraftHeaderAction(): Action
    {
        return $this->makeSaveDraftAction('saveDraftHeader');
    }

    /**
     * The shared "Entwurf speichern" definition (footer + header mirror).
     * Disabled while the form matches its pristine baseline (nothing to
     * stash); rendered disabled and released client-side, since dirtiness
     * only exists in the browser until a Livewire roundtrip.
     */
    protected function makeSaveDraftAction(string $name): Action
    {
        return Action::make($name)
            ->label('Entwurf speichern')
            ->color('gray')
            ->action('saveDraft')
            ->extraAttributes([
                'disabled' => true,
                'x-data' => '{ draftPristine: true, draftPristineTimer: null }',
                'x-effect' => $this->draftPristineEffectJs(),
                'x-bind:disabled' => 'draftPristine',
            ])
            ->visible(fn (): bool => $this->draftsSupported());
    }

    /** The delete action, moved out of the header: icon-only, at the footer's end. */
    protected function getDeleteFormAction(): Action
    {
        return DeleteAction::make()
            ->iconButton()
            ->icon(Heroicon::OutlinedTrash)
            ->tooltip('Löschen');
    }

    protected function getApplyHeaderAction(): Action
    {
        return Action::make('applyHeader')
            ->label($this->draftsSupported() ? 'Änderungen anwenden' : 'Speichern')
            ->action('save');
    }

    /**
     * "Vorschau": stash the CURRENT form state as the draft first, then open
     * the frontend in preview mode (?preview=1) — so the preview always shows
     * exactly what the form shows.
     *
     * The tab is opened synchronously inside the click gesture (otherwise
     * popup blockers kill it) and pointed at the URL only after saveDraft()
     * confirmed the stash; on a validation failure it closes again and the
     * form displays the errors. With the popup blocked anyway, the current
     * tab navigates as fallback — the stash made that lossless. The button
     * itself only locks for the duration of the roundtrip ($el.disabled) —
     * it must stay clickable no matter the pristine/draft state.
     */
    protected function getPreviewAction(): Action
    {
        $url = $this->previewUrl();

        return Action::make('preview')
            ->label('Vorschau')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->alpineClickHandler(
                'if ($el.disabled) { return; } $el.disabled = true; '
                ."const previewWindow = window.open('about:blank', '_blank'); "
                // The tab opens before the roundtrip (popup-blocker constraint) —
                // show what is happening instead of a blank page.
                ."if (previewWindow) { previewWindow.document.title = 'Vorschau'; previewWindow.document.body.innerText = 'Entwurf wird gespeichert – die Vorschau öffnet sich gleich …'; } "
                .'$wire.saveDraft().then((saved) => { '
                .'if (saved !== true) { previewWindow?.close(); return; } '
                .'if (previewWindow) { previewWindow.location.href = '.Js::from($url).'; } '
                .'else { window.location.href = '.Js::from($url).'; } '
                .'}).catch(() => previewWindow?.close())'
                .'.finally(() => { $el.disabled = false; });'
            )
            ->visible(fn (): bool => $this->draftsSupported() && $url !== null);
    }

    protected function getDiscardDraftAction(): Action
    {
        return Action::make('discardDraft')
            ->label('Entwurf verwerfen')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Entwurf verwerfen?')
            ->modalDescription('Der gespeicherte Entwurf wird gelöscht. Die zuletzt angewendete Fassung bleibt unverändert.')
            ->visible(fn (): bool => Drafts::pending($this->getRecord()))
            ->action(function (): void {
                $this->getRecord()->discardDraft();

                // Reload the applied values into the form and reset dirty tracking.
                $this->fillForm();
                $this->rememberData();

                Notification::make()
                    ->success()
                    ->title('Entwurf verworfen')
                    ->send();
            });
    }

    // -------------------------------------------------------------------------
    //  Support
    // -------------------------------------------------------------------------

    protected function draftsSupported(): bool
    {
        return Drafts::supported($this->getRecord());
    }

    /**
     * Client-side pristine tracking for the draft buttons. Reading the full
     * $wire.data keeps the Alpine effect subscribed to every form mutation;
     * the md5 itself is debounced so large builder states are not hashed on
     * every keystroke.
     */
    protected function draftPristineEffectJs(): string
    {
        return 'const draftData = JSON.stringify($wire.data); const draftHash = $wire.draftSavedDataHash; '
            .'clearTimeout(draftPristineTimer); '
            ."draftPristineTimer = setTimeout(() => { draftPristine = window.jsMd5(draftData.replace(/\\\\/g, '')) === draftHash; }, 250);";
    }

    /**
     * The frontend path the "Vorschau" action opens. Pages with an own URL
     * override this; the base points at the homepage (fragments and
     * non-routable types surface wherever they are embedded).
     */
    protected function previewPath(): string
    {
        return '/';
    }

    protected function previewUrl(): ?string
    {
        if (! Route::has('content.show')) {
            return null;
        }

        $path = ltrim($this->previewPath(), '/');

        $parameters = [PreviewMode::QUERY_PARAM => 1];

        // No array_filter here: it would also drop the legitimate path '0'.
        if ($path !== '') {
            $parameters['path'] = $path;
        }

        return route('content.show', $parameters);
    }
}
