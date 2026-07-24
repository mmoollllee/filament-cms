<?php

namespace Mmoollllee\Cms\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Support\Preview\Drafts;

/**
 * The secondary "hold it back" create action for the content + fragment create
 * pages — the create side of {@see ManagesDrafts}.
 *
 * The button runs the exact create() pipeline (validation, hooks, notification,
 * redirect), but the applied (live) row is the NEUTRALIZED form state. What
 * "held back" means depends on the model:
 *
 * - Content ("Unveröffentlicht anlegen"): the publishing window is emptied and
 *   the row simply starts unpublished — visible in the Vorschau, invisible to
 *   guests. NO draft stash is created: an unpublished row protects nothing, so
 *   duplicating its state into a stash would only reopen the edit page in a
 *   pointless "Entwurf geladen" state ({@see shouldStashOnDraftCreation()}).
 * - Fragments ("Als Entwurf anlegen"): fragments have no publishing window —
 *   they are live wherever they are embedded. The applied row is neutralized
 *   to no active blocks and the FULL form state is stashed as a pending draft,
 *   so the edit page continues directly in the draft workflow.
 *
 * On a model that supports neither variant the button hides and the page keeps
 * the classic create-only flow ({@see draftsSupportedForCreation()}).
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
     * Held-back creation: persist the neutralized state as the applied row —
     * and stash the full form state as a pending draft only where a stash has
     * a job ({@see shouldStashOnDraftCreation()}).
     */
    protected function handleRecordCreation(array $data): Model
    {
        if (! $this->creatingAsDraft) {
            return parent::handleRecordCreation($data);
        }

        $record = parent::handleRecordCreation($this->neutralizeDraftCreationData($data));

        if ($this->shouldStashOnDraftCreation() && Drafts::supported($record)) {
            $record->stashDraft($data);
        }

        return $record;
    }

    /**
     * Whether the held-back create also stashes the full form state as a
     * pending draft. True for window-less models (fragments) whose neutralized
     * row would otherwise lose the entered state; content pages return false —
     * their applied row keeps the full state and is merely unpublished.
     */
    protected function shouldStashOnDraftCreation(): bool
    {
        return true;
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
            $notification?->title($this->draftCreatedNotificationTitle())
                ->body($this->draftCreatedNotificationBody());
        }

        return $notification;
    }

    protected function draftCreatedNotificationTitle(): string
    {
        return 'Als Entwurf angelegt';
    }

    protected function draftCreatedNotificationBody(): string
    {
        return 'Gespeichert, aber noch nicht angewendet — sichtbar über die Vorschau.';
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

    /** The shared held-back-create definition (footer + header mirror). */
    protected function makeCreateDraftAction(string $name): Action
    {
        return Action::make($name)
            ->label($this->createDraftActionLabel())
            ->color('gray')
            ->action('createAsDraft')
            ->visible(fn (): bool => $this->draftsSupportedForCreation());
    }

    protected function createDraftActionLabel(): string
    {
        return 'Als Entwurf anlegen';
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
