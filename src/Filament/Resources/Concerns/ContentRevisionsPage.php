<?php

namespace Mmoollllee\Cms\Filament\Resources\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Mansoor\FilamentVersionable\RevisionsPage;
use Mmoollllee\Cms\Support\Media\MediaLibrary;
use Mmoollllee\Cms\Support\Media\MediaReferences;
use Mmoollllee\Cms\Support\Media\References\ContentMediaReferences;
use Mmoollllee\Cms\Support\Preview\Drafts;

/**
 * Base revisions page for every versionable CMS resource (contents AND
 * fragments) — the "Revisionen" history with side-by-side diff and restore,
 * provided by mansoor/filament-versionable. A concrete page only pins its
 * `$resource` and is registered as the resource's `revisions` route:
 *
 *     class Revisions extends ContentRevisionsPage
 *     {
 *         protected static string $resource = Resource::class;
 *     }
 *
 *     // Resource::getPages():  'revisions' => Revisions::route('/{record}/revisions'),
 */
abstract class ContentRevisionsPage extends RevisionsPage
{
    protected Width|string|null $maxContentWidth = Width::ScreenTwoExtraLarge;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Records that predate the versions table have no history yet — the
        // action linking here is hidden then, but guard direct URLs too.
        abort_if($this->version === null, 404);
    }

    /**
     * Restoring is a deliberate state decision: a pending draft stash would
     * otherwise re-overlay the just-restored content in the edit form (and
     * overwrite it again on the next "Änderungen anwenden") — discard it,
     * but only AFTER the parent restore succeeded: a failed restore must
     * never destroy the stash.
     */
    public function restoreVersion(): void
    {
        // restoreVersion() is a public Livewire method and the blade only
        // guards its BUTTON — with no older state to restore (initial version
        // only, or a stale/bogus version id) the parent would fatal on
        // previousVersion()->revert().
        if ($this->version?->previousVersion() === null) {
            Notification::make()
                ->warning()
                ->title('Keine ältere Fassung vorhanden')
                ->send();

            return;
        }

        $record = $this->getRecord();
        $hadPendingDraft = Drafts::pending($record);

        try {
            parent::restoreVersion();
        } catch (ValidationException $exception) {
            // The saving hook refuses what the revision would bring back — a parent since
            // deleted, moved below the page, or no longer allowed. This page draws no field
            // errors, so the answer is a notice; nothing was restored and the stash stays.
            Notification::make()
                ->danger()
                ->title('Fassung nicht wiederhergestellt')
                ->body(implode(' ', $exception->validator->errors()->all()))
                ->send();

            return;
        }

        if ($hadPendingDraft) {
            $record->discardDraft();

            Notification::make()
                ->warning()
                ->title('Offener Entwurf verworfen')
                ->body('Beim Wiederherstellen wurde der noch nicht angewendete Entwurf verworfen.')
                ->send();
        }

        $this->warnAboutMissingFiles($record);
    }

    /**
     * A revision can show files deleted after it was saved: the record now
     * points at them, and the page shows nothing in their place.
     */
    protected function warnAboutMissingFiles(Model $record): void
    {
        if (! MediaLibrary::enabled()) {
            return;
        }

        $missing = count(MediaReferences::missingIn($record, ContentMediaReferences::APPLIED_COLUMNS));

        if ($missing === 0) {
            return;
        }

        Notification::make()
            ->warning()
            ->persistent()
            ->title($missing === 1 ? 'Eine Datei dieser Fassung gibt es nicht mehr' : "{$missing} Dateien dieser Fassung gibt es nicht mehr")
            ->body($missing === 1
                ? 'Sie wurde gelöscht, nachdem die Fassung gespeichert war. An ihrer Stelle bleibt die Seite leer, bis du dort eine andere Datei wählst.'
                : 'Sie wurden gelöscht, nachdem die Fassung gespeichert war. An ihrer Stelle bleibt die Seite leer, bis du dort andere Dateien wählst.')
            ->send();
    }
}
