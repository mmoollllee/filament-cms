<?php

namespace Mmoollllee\Cms\Support\Media;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Mmoollllee\Cms\Exceptions\MediaItemInUse;
use Mmoollllee\Cms\Filament\Support\MediaUsageList;
use Mmoollllee\Cms\Support\Media\References\MediaUsage;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

/**
 * Nothing that a record still uses can be deleted from the Mediathek — not
 * a file, not a folder holding one, not a selection containing one. Deleting
 * a used image used to make it vanish from the live page without a word; now
 * the editor is told where it is used and removes it there first.
 *
 * "Used" means any reference {@see MediaReferences} finds: on a published
 * page, an unpublished one, in an unapplied draft, in the tenant settings.
 * Draft-only uses count too: the draft would lose the picture on its next
 * save.
 *
 * Three layers, so no path around it stays open:
 * 1. the delete actions show the uses up front and offer no confirm button
 *    ({@see \Mmoollllee\Cms\CmsServiceProvider});
 * 2. the driver refuses before deleting anything — folder contents and
 *    selections included ({@see CmsMediaLibraryDriver::deleteFile()});
 * 3. the item model refuses for code that deletes an item directly
 *    ({@see registerModelGuard()}).
 */
final class MediaDeletionGuard
{
    private static int $bypass = 0;

    /**
     * The items a file stands for: the item itself, or everything inside a
     * folder, subfolders included.
     *
     * @return Collection<int, MediaLibraryItem>
     */
    public static function itemsIn(FileData $file, MediaLibraryItemDriver $driver): Collection
    {
        if ($file->getType() === FileType::Folder) {
            $folder = $driver->getMediaLibraryFolderModel()::query()->find($file->getPrimaryKey());

            return $folder === null ? collect() : self::itemsInFolder($folder);
        }

        // The library's listing loaded the item already.
        $source = method_exists($file, 'getSource') ? $file->getSource() : null;
        $item = $source instanceof MediaLibraryItem
            ? $source
            : $driver->getMediaLibraryItemModel()::query()->find($file->getPrimaryKey());

        return collect($item === null ? [] : [$item]);
    }

    /**
     * @param  iterable<FileData>  $files
     * @return array<int, array{item: MediaLibraryItem, usages: Collection<int, MediaUsage>}>
     */
    public static function blockedBy(iterable $files, MediaLibraryItemDriver $driver): array
    {
        $blocked = [];

        foreach ($files as $file) {
            $blocked += MediaReferences::inUse(self::itemsIn($file, $driver));
        }

        return $blocked;
    }

    /**
     * Tell the editor why nothing was deleted.
     *
     * @param  array<int, array{item: MediaLibraryItem, usages: Collection<int, MediaUsage>}>  $blocked
     */
    public static function notify(array $blocked): void
    {
        $advice = count($blocked) === 1
            ? 'Entferne die Datei dort zuerst – danach lässt sie sich löschen.'
            : 'Entferne die Dateien dort zuerst – danach lassen sie sich löschen.';

        Notification::make()
            ->danger()
            ->persistent()
            ->title(self::title($blocked))
            ->body(MediaUsageList::blockedHtml($blocked)->toHtml().'<p style="margin-block-start: 0.75rem;">'.$advice.'</p>')
            ->send();
    }

    /**
     * After a delete was refused.
     *
     * @param  array<int, array{item: MediaLibraryItem, usages: Collection<int, MediaUsage>}>  $blocked
     */
    public static function title(array $blocked): string
    {
        return count($blocked) === 1
            ? self::inUseTitle($blocked).' und wurde nicht gelöscht'
            : self::inUseTitle($blocked).' – es wurde nichts gelöscht';
    }

    /**
     * Before anything was tried: which files are still used.
     *
     * @param  array<int, array{item: MediaLibraryItem, usages: Collection<int, MediaUsage>}>  $blocked
     */
    public static function inUseTitle(array $blocked): string
    {
        return count($blocked) === 1
            ? '„'.MediaUsageList::fileName(reset($blocked)['item']).'“ wird noch verwendet'
            : count($blocked).' Dateien werden noch verwendet';
    }

    /**
     * The model-level line of defense: a `deleting` listener that refuses
     * while the item is in use.
     *
     * It must run before the media library's own `deleting` listener, which
     * removes the files — refusing after that would keep an item without a
     * file. Model listeners fire in registration order and the library adds
     * its listener when the model class boots, so this one is put in front of
     * whatever is registered already.
     *
     * @param  class-string<MediaLibraryItem>  $model
     */
    public static function registerModelGuard(string $model): void
    {
        $dispatcher = Model::getEventDispatcher();

        if ($dispatcher === null) {
            return;
        }

        $event = "eloquent.deleting: {$model}";
        $guard = function (MediaLibraryItem $item): void {
            self::assertDeletable([$item]);
        };

        // A dispatcher that cannot list its listeners cannot have them put
        // back either: the guard goes last there rather than taking the
        // others with it.
        if (! method_exists($dispatcher, 'getRawListeners')) {
            $dispatcher->listen($event, $guard);

            return;
        }

        $existing = $dispatcher->getRawListeners()[$event] ?? [];

        $dispatcher->forget($event);
        $dispatcher->listen($event, $guard);

        foreach ($existing as $listener) {
            $dispatcher->listen($event, $listener);
        }
    }

    /**
     * @param  iterable<MediaLibraryItem>  $items
     *
     * @throws MediaItemInUse
     */
    public static function assertDeletable(iterable $items): void
    {
        if (self::$bypass > 0) {
            return;
        }

        $blocked = MediaReferences::inUse($items);

        if ($blocked !== []) {
            throw new MediaItemInUse($blocked);
        }
    }

    /**
     * Run code that deletes items on purpose — a whole tenant being removed,
     * say — without the guard.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutProtection(callable $callback): mixed
    {
        self::$bypass++;

        try {
            return $callback();
        } finally {
            self::$bypass--;
        }
    }

    /**
     * @return Collection<int, MediaLibraryItem>
     */
    private static function itemsInFolder(MediaLibraryFolder $folder): Collection
    {
        $items = $folder->mediaLibraryItems()->with('tenant')->get();

        foreach ($folder->children()->get() as $child) {
            $items = $items->merge(self::itemsInFolder($child));
        }

        return $items;
    }
}
