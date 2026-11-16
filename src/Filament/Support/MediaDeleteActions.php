<?php

namespace Mmoollllee\Cms\Filament\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Mmoollllee\Cms\Support\Media\MediaDeletionGuard;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Drivers\Contracts\Driver;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\Explore\Filament\Actions\DeleteAction;
use RalphJSmit\Filament\Explore\Filament\Actions\DeleteBulkAction;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use WeakMap;

/**
 * The Mediathek's delete dialogs say up front when something is still used —
 * which file, where, in which state — and then offer no button that would
 * delete it. The driver refuses anyway ({@see MediaDeletionGuard}); this is
 * the part the editor sees before trying.
 *
 * The vendor's own heading and description stay in place for everything
 * that may be deleted: they are read off the action before being wrapped, so
 * a plugin update that rewords them still shows through.
 */
final class MediaDeleteActions
{
    /** @var WeakMap<object, array<string, array<int, array<string, mixed>>>>|null what an open dialog found, per action */
    private static ?WeakMap $found = null;

    public static function configure(): void
    {
        DeleteAction::configureUsing(function (DeleteAction $action): void {
            $heading = self::property($action, 'modalHeading');
            $description = self::property($action, 'modalDescription');

            $action
                ->modalHeading(function (DeleteAction $action, Driver $driver, FileData $file) use ($heading) {
                    $blocked = $file->getType() === FileType::File ? self::blocked($driver, [$file], $action) : [];

                    if ($blocked !== []) {
                        return MediaDeletionGuard::inUseTitle($blocked);
                    }

                    return $action->evaluate($heading);
                })
                ->modalDescription(function (DeleteAction $action, Driver $driver, FileData $file) use ($description) {
                    $blocked = self::blocked($driver, [$file], $action);

                    if ($blocked === []) {
                        return $action->evaluate($description);
                    }

                    if ($file->getType() === FileType::Folder) {
                        return new HtmlString(e((string) $action->evaluate($description))
                            .'<p style="margin-block-start: 0.75rem;"><strong>Der Ordner enthält Dateien, die noch verwendet werden.</strong> Mit Inhalt lässt er sich deshalb nicht löschen – ohne Inhalt schon: die Dateien wandern dann in den übergeordneten Ordner.</p>'
                            .MediaUsageList::blockedHtml($blocked)->toHtml());
                    }

                    return new HtmlString('<p>Diese Datei wird hier verwendet:</p>'
                        .MediaUsageList::html(reset($blocked)['usages'])->toHtml()
                        .'<p style="margin-block-start: 0.75rem;">Entferne sie dort zuerst – danach lässt sie sich löschen.</p>');
                })
                ->modalSubmitAction(fn (DeleteAction $action, Driver $driver, FileData $file) => $file->getType() === FileType::File && self::blocked($driver, [$file], $action) !== [] ? false : null)
                ->modalCancelActionLabel(fn (DeleteAction $action, Driver $driver, FileData $file) => $file->getType() === FileType::File && self::blocked($driver, [$file], $action) !== [] ? 'Schließen' : null);
        });

        DeleteBulkAction::configureUsing(function (DeleteBulkAction $action): void {
            $description = self::property($action, 'modalDescription');

            $action
                ->modalDescription(function (DeleteBulkAction $action, Driver $driver, ?Collection $files) use ($description) {
                    $blocked = self::blocked($driver, $files ?? [], $action);

                    if ($blocked === []) {
                        return $action->evaluate($description);
                    }

                    return new HtmlString('<p><strong>'.e(MediaDeletionGuard::inUseTitle($blocked)).'.</strong> '.self::bulkAdvice($blocked, $files ?? collect()).'</p>'
                        .MediaUsageList::blockedHtml($blocked)->toHtml());
                })
                ->modalSubmitAction(fn (DeleteBulkAction $action, Driver $driver, ?Collection $files) => self::blocked($driver, $files ?? [], $action) !== [] ? false : null)
                // All or nothing: a selection holding one used file deletes none,
                // instead of stopping halfway through the list.
                ->before(function (DeleteBulkAction $action, Driver $driver, ?Collection $files): void {
                    $blocked = self::blocked($driver, $files ?? []);

                    if ($blocked !== []) {
                        MediaDeletionGuard::notify($blocked);

                        $action->halt();
                    }
                });
        });
    }

    /**
     * What blocks deleting the files. Asked once per dialog: heading,
     * description and buttons all render from the same answer. The
     * `before()` check right before deleting asks again, without `$action`.
     *
     * @param  iterable<FileData>  $files
     * @return array<int, array<string, mixed>>
     */
    private static function blocked(Driver $driver, iterable $files, ?object $action = null): array
    {
        if (! $driver instanceof MediaLibraryItemDriver) {
            return [];
        }

        if ($action === null) {
            return MediaDeletionGuard::blockedBy($files, $driver);
        }

        $files = collect($files);
        $key = $files->map(fn (FileData $file): string => $file->getType()->name.':'.$file->getPrimaryKey())->implode(',');

        self::$found ??= new WeakMap;
        $found = self::$found[$action] ?? [];

        if (! array_key_exists($key, $found)) {
            $found[$key] = MediaDeletionGuard::blockedBy($files, $driver);
            self::$found[$action] = $found;
        }

        return $found[$key];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocked
     * @param  Collection<int, FileData>  $files
     */
    private static function bulkAdvice(array $blocked, Collection $files): string
    {
        $one = count($blocked) === 1;
        $where = $one ? 'wo sie verwendet wird' : 'wo sie verwendet werden';

        // A file inside a selected folder cannot be taken out of the selection on its own.
        if ($files->contains(fn (FileData $file): bool => $file->getType() === FileType::Folder)) {
            return 'Nimm '.($one ? 'sie – oder den Ordner, in dem sie liegt –' : 'sie – oder die Ordner, in denen sie liegen –')
                .' aus der Auswahl, oder entferne sie zuerst dort, '.$where.':';
        }

        return 'Nimm sie aus der Auswahl oder entferne sie zuerst dort, '.$where.':';
    }

    /**
     * A modal text as the vendor configured it — the raw closure or string,
     * so it can be evaluated later in the action's own context. Null should a
     * plugin update rename it: then the vendor's default shows, instead of
     * every delete action failing to build.
     */
    private static function property(object $action, string $name): mixed
    {
        return property_exists($action, $name) ? (fn () => $this->{$name})->call($action) : null;
    }
}
