<?php

namespace Mmoollllee\Cms\Filament\Forms;

use DefStudio\SearchableInput\DTO\SearchResult;
use DefStudio\SearchableInput\Forms\Components\SearchableInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Pre-configured SearchableInput factories for link fields (href + label).
 *
 * WordPress-style autocomplete over the tenant's routable Content paths,
 * rendered with the package's two-line suggestion dropdown (title + path,
 * {@see resources/views/filament/forms/link-suggestions-wrapper.blade.php}).
 *
 * Used by the RichEditor (LinkPickerPlugin, ButtonGroupBlock,
 * NavigationCardGroupBlock) and reusable in any consumer resource form:
 *
 *     ContentPathSuggestions::makeHrefInput('payload.link')->label('Link'),
 *
 * The factories only wire search + suggestion UX; layout concerns (labels,
 * column spans, required) stay at the call site.
 */
final class ContentPathSuggestions
{
    /**
     * SearchableInput for an href field.
     *
     * Searches Content by path and title, fills the field with the path only.
     */
    public static function makeHrefInput(string $name = 'href'): SearchableInput
    {
        return self::baseInput($name)
            ->label('URL')
            ->placeholder('/relativer-pfad oder https://...')
            ->searchUsing(fn (string $search): array => self::search($search));
    }

    /**
     * SearchableInput for an href field that auto-fills a sibling label field.
     *
     * When the user selects a suggestion, the path goes into href and
     * the Content title goes into the specified label field (if still empty).
     */
    public static function makeHrefInputWithLabel(string $name = 'href', string $labelField = 'label'): SearchableInput
    {
        return self::makeHrefInput($name)
            ->onItemSelected(function (SearchResult $item, Get $get, Set $set) use ($labelField): void {
                $title = $item->get('title');

                if (filled($title) && blank($get($labelField))) {
                    $set($labelField, $title);
                }
            });
    }

    /**
     * SearchableInput for a label/text field that auto-fills a sibling href field.
     *
     * Searches Content by title and path. When selected, the title goes
     * into the label field and the path into the specified href field (if still empty).
     */
    public static function makeLabelInput(string $name = 'label', string $hrefField = 'href'): SearchableInput
    {
        return self::baseInput($name)
            ->label('Beschriftung')
            ->placeholder('Buttonbeschriftung')
            ->searchUsing(fn (string $search): array => self::searchByTitle($search))
            ->onItemSelected(function (SearchResult $item, Get $get, Set $set) use ($hrefField): void {
                $path = $item->get('path');

                if (filled($path) && blank($get($hrefField))) {
                    $set($hrefField, $path);
                }
            });
    }

    /**
     * Shared base: a SearchableInput rendered with the package's styled
     * suggestion dropdown instead of the vendor wrapper.
     */
    private static function baseInput(string $name): SearchableInput
    {
        return SearchableInput::make($name)
            ->fieldWrapperView('cms-link-suggestions-wrapper');
    }

    /**
     * Search Content by path — path is the value the href field fills with.
     *
     * @return array<int, SearchResult>
     */
    protected static function search(string $search): array
    {
        return self::results($search, valueColumn: 'path');
    }

    /**
     * Search Content by title — title is the value the label field fills with.
     *
     * @return array<int, SearchResult>
     */
    protected static function searchByTitle(string $search): array
    {
        return self::results($search, valueColumn: 'title');
    }

    /**
     * Run the shared query and map to two-line suggestions. `valueColumn`
     * decides which column fills the field (path for href, title for label);
     * every result carries title + path as data for the dropdown either way.
     *
     * @return array<int, SearchResult>
     */
    private static function results(string $search, string $valueColumn): array
    {
        $query = self::baseQuery($search);

        if ($query === null) {
            return [];
        }

        return $query
            ->orderBy($valueColumn)
            ->get(['title', 'path'])
            ->map(fn (Content $content): SearchResult => SearchResult::make(
                value: $content->{$valueColumn},
                label: "{$content->title} — {$content->path}",
            )->withData('title', $content->title)->withData('path', $content->path))
            ->all();
    }

    /**
     * Base query for searching routable Content by path or title.
     *
     * @return ?Builder<Content>
     */
    protected static function baseQuery(string $search): ?Builder
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant === null) {
            return null;
        }

        return Cms::contentModel()::query()
            ->whereBelongsTo($tenant)
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->where(function ($query) use ($search): void {
                $query
                    ->where('path', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            })
            ->limit(15);
    }
}
