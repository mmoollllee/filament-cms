<?php

namespace Mmoollllee\Cms\Filament\Resources\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Mansoor\FilamentVersionable\RevisionsPage;
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

        parent::restoreVersion();

        if ($hadPendingDraft) {
            $record->discardDraft();

            Notification::make()
                ->warning()
                ->title('Offener Entwurf verworfen')
                ->body('Beim Wiederherstellen wurde der noch nicht angewendete Entwurf verworfen.')
                ->send();
        }
    }
}
