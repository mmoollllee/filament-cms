<?php

namespace Mmoollllee\Cms\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Support\Preview\Drafts;

/**
 * "Als Entwurf anlegen" for the content + fragment create pages — the create
 * side of {@see ManagesDrafts}.
 *
 * The button runs the exact create() pipeline (validation, hooks, notification,
 * redirect), but the applied (live) row is the NEUTRALIZED form state — content
 * pages empty the publishing window, the fragment page the active blocks — and
 * the full form state is stashed as a pending draft. The edit page the user is
 * redirected to therefore opens directly in the draft workflow ("Entwurf …
 * noch nicht angewendet", Vorschau, "Änderungen anwenden").
 *
 * On a model without the HasDraft trait the button hides and the page keeps the
 * classic create-only flow ({@see Drafts::supported()}).
 *
 * NOTE for subclasses: this trait implements handleRecordCreation(),
 * getCreatedNotification(), getFormActions() and getHeaderActions(). Overrides
 * shadow the trait — call the parent implementation to keep draft creation
 * working.
 */
trait CreatesDrafts
{
    /** Whether the running create() was started via createAsDraft(). */
    protected bool $creatingAsDraft = false;

    // -------------------------------------------------------------------------
    //  Livewire methods
    // -------------------------------------------------------------------------

    public function createAsDraft(): void
    {
        if (! $this->draftsSupportedForCreation()) {
            return;
        }

        $this->creatingAsDraft = true;

        try {
            $this->create();
        } finally {
            $this->creatingAsDraft = false;
        }
    }

    // -------------------------------------------------------------------------
    //  Create pipeline
    // -------------------------------------------------------------------------

    /**
     * Draft creation: persist the neutralized state as the applied row, stash
     * the full form state as the pending draft.
     */
    protected function handleRecordCreation(array $data): Model
    {
        if (! $this->creatingAsDraft) {
            return parent::handleRecordCreation($data);
        }

        $record = parent::handleRecordCreation($this->neutralizeDraftCreationData($data));

        if (Drafts::supported($record)) {
            $record->stashDraft($data);
        }

        return $record;
    }

    /**
     * Strip what must not go live when creating as draft — the stash keeps the
     * full state. Identity by default; each page declares its own neutral form
     * (content: no publishing window, fragment: no active blocks).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function neutralizeDraftCreationData(array $data): array
    {
        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        $notification = parent::getCreatedNotification();

        if ($this->creatingAsDraft) {
            $notification?->title('Als Entwurf angelegt')
                ->body('Gespeichert, aber noch nicht angewendet — sichtbar über die Vorschau.');
        }

        return $notification;
    }

    // -------------------------------------------------------------------------
    //  Actions
    // -------------------------------------------------------------------------

    /**
     * Footer: [Erstellen] [Als Entwurf anlegen] [Erstellen & weiteres] [Abbrechen].
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateDraftFormAction(),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Header mirror of the create pair, matching the edit pages. Every action
     * carries a stable wire:key: the draft button toggles visibility, and
     * unkeyed siblings inherit each other's DOM state on the resulting morph
     * shift (same hazard the edit-page header documents).
     */
    protected function getHeaderActions(): array
    {
        return array_map(
            fn (Action $action): Action => $action->extraAttributes(
                ['wire:key' => 'cms-header-action-'.$action->getName()],
                merge: true,
            ),
            [
                $this->getCreateDraftHeaderAction(),
                $this->getCreateHeaderAction(),
            ],
        );
    }

    protected function getCreateDraftFormAction(): Action
    {
        return $this->makeCreateDraftAction('createDraft');
    }

    protected function getCreateDraftHeaderAction(): Action
    {
        return $this->makeCreateDraftAction('createDraftHeader');
    }

    /** The shared "Als Entwurf anlegen" definition (footer + header mirror). */
    protected function makeCreateDraftAction(string $name): Action
    {
        return Action::make($name)
            ->label('Als Entwurf anlegen')
            ->color('gray')
            ->action('createAsDraft')
            ->visible(fn (): bool => $this->draftsSupportedForCreation());
    }

    protected function getCreateHeaderAction(): Action
    {
        return Action::make('createHeader')
            ->label(__('filament-panels::resources/pages/create-record.form.actions.create.label'))
            ->action('create');
    }

    // -------------------------------------------------------------------------
    //  Support
    // -------------------------------------------------------------------------

    /** Class-level check — there is no record yet while creating. */
    protected function draftsSupportedForCreation(): bool
    {
        return Drafts::supported(static::getResource()::getModel());
    }
}
