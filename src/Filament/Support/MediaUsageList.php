<?php

namespace Mmoollllee\Cms\Filament\Support;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Filament\Resources\Menus\MenuResource;
use Mmoollllee\Cms\Filament\Resources\Redirects\RedirectResource;
use Mmoollllee\Cms\Filament\Widgets\Concerns\ResolvesContentResourceUrls;
use Mmoollllee\Cms\Models\Menu;
use Mmoollllee\Cms\Models\Redirect;
use Mmoollllee\Cms\Support\Media\References\MediaUsage;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Throwable;

/**
 * The editor-facing form of {@see MediaUsage}s: who uses a file, as what, in
 * which state — linked to the page that edits the record where the user may
 * open it. Shared by the Mediathek's file info, the delete confirmation and
 * the notification a blocked delete sends, so all three say the same thing.
 */
final class MediaUsageList
{
    use ResolvesContentResourceUrls;

    /** Entries shown before the rest is summed up as "… und N weitere". */
    public const LIMIT = 8;

    /**
     * @param  Collection<int, MediaUsage>  $usages
     */
    public static function html(Collection $usages, int $limit = self::LIMIT): HtmlString
    {
        $list = new self;
        $lines = $usages
            ->sortBy(fn (MediaUsage $usage): string => ($usage->isPublic() ? '0' : '1').mb_strtolower($usage->label))
            ->take($limit)
            ->map(fn (MediaUsage $usage): string => '<li>'.$list->line($usage).'</li>');

        if ($usages->count() > $limit) {
            $lines->push('<li>… und '.($usages->count() - $limit).' weitere</li>');
        }

        return new HtmlString('<ul style="list-style: disc; padding-inline-start: 1.25rem; display: grid; gap: 0.25rem;">'.$lines->implode('').'</ul>');
    }

    /**
     * The blocked files of a delete, each with its uses.
     *
     * @param  array<int, array{item: MediaLibraryItem, usages: Collection<int, MediaUsage>}>  $blocked
     */
    public static function blockedHtml(array $blocked): HtmlString
    {
        $parts = [];

        foreach (array_slice($blocked, 0, 5, true) as $entry) {
            $parts[] = '<p style="margin-block-start: 0.5rem;"><strong>'.e(self::fileName($entry['item'])).'</strong></p>'
                .self::html($entry['usages'], 4)->toHtml();
        }

        if (count($blocked) > 5) {
            $parts[] = '<p style="margin-block-start: 0.5rem;">… und '.(count($blocked) - 5).' weitere Dateien</p>';
        }

        return new HtmlString(implode('', $parts));
    }

    public static function fileName(MediaLibraryItem $item): string
    {
        // getItem() is typed non-null and throws for an item whose file is gone.
        $media = $item->getFirstMedia($item->getMediaLibraryCollectionName());

        return (string) ($media?->file_name ?? $media?->name ?? '#'.$item->getKey());
    }

    private function line(MediaUsage $usage): string
    {
        $type = $this->typeLabel($usage->owner);
        $label = e($usage->label).($usage->detail !== null ? ' – '.e($usage->detail) : '');
        $url = $this->url($usage->owner);

        if ($url !== null) {
            $label = '<a href="'.e($url).'" style="text-decoration: underline;">'.$label.'</a>';
        }

        return $label.' <span style="opacity: 0.7;">('.e($type).', '.e($usage->statusLabel()).')</span>';
    }

    private function typeLabel(Model $owner): string
    {
        $label = match (true) {
            $owner instanceof Tenant => 'Website-Einstellungen',
            $owner instanceof Menu => 'Menü',
            $owner instanceof Redirect => 'Weiterleitung',
            default => rescue(fn (): ?string => $this->typeLabelForRecord($owner), null, report: false),
        };

        // A record of an app's own source has no content type to name.
        return filled($label) ? (string) $label : 'Eintrag';
    }

    private function url(Model $owner): ?string
    {
        // Outside a panel request (queue, console) there is nothing to link to.
        try {
            return match (true) {
                $owner instanceof Tenant => Filament::getTenant()?->is($owner) ? Filament::getTenantProfileUrl() : null,
                $owner instanceof Menu => self::editUrl(MenuResource::class, $owner),
                $owner instanceof Redirect => self::editUrl(RedirectResource::class, $owner),
                default => $this->resourceUrlForRecord($owner, 'edit'),
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private static function editUrl(string $resource, Model $record): ?string
    {
        return $resource::hasPage('edit') && $resource::canAccess() ? $resource::getUrl('edit', ['record' => $record]) : null;
    }
}
