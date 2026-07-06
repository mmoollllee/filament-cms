<?php

namespace Mmoollllee\Cms\Filament\RichEditor;

use DefStudio\SearchableInput\DTO\SearchResult;
use DefStudio\SearchableInput\Forms\Components\SearchableInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Contracts\Content;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Provides pre-configured SearchableInput fields for URL/href and label fields.
 *
 * Used by LinkPickerPlugin, ButtonGroupBlock, and NavigationCardGroupBlock
 * to offer WordPress-style autocomplete for internal Content paths.
 *
 * @see SearchableInput
 */
final class ContentPathSuggestions
{
    /**
     * SearchableInput for a standalone href field (LinkPicker).
     *
     * Searches Content by path and title, fills the field with the path only.
     */
    public static function makeHrefInput(string $name = 'href'): SearchableInput
    {
        return SearchableInput::make($name)
            ->hiddenLabel()
            ->placeholder('/relativer-pfad oder https://...')
            ->required()
            ->columnSpan('4')
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
        return SearchableInput::make($name)
            ->hiddenLabel()
            ->placeholder('Buttonbeschriftung')
            ->required()
            ->columnSpan('4')
            ->live()
            ->searchUsing(function (string $search): array {
                return static::searchByTitle($search);
            })
            ->onItemSelected(function (SearchResult $item, Get $get, Set $set) use ($hrefField): void {
                $path = $item->get('path');

                if (filled($path) && blank($get($hrefField))) {
                    $set($hrefField, $path);
                }
            });
    }

    /**
     * Search Content by path — returns path as value, attaches title as data.
     *
     * @return array<int, SearchResult>
     */
    protected static function search(string $search): array
    {
        $query = self::baseQuery($search);

        if ($query === null) {
            return [];
        }

        return $query
            ->orderBy('path')
            ->get(['title', 'path'])
            ->map(fn (Content $content): SearchResult => SearchResult::make(
                value: $content->path,
                label: "{$content->title}  —  {$content->path}",
            )->withData('title', $content->title))
            ->all();
    }

    /**
     * Search Content by title — returns title as value, attaches path as data.
     *
     * @return array<int, SearchResult>
     */
    protected static function searchByTitle(string $search): array
    {
        $query = self::baseQuery($search);

        if ($query === null) {
            return [];
        }

        return $query
            ->orderBy('title')
            ->get(['title', 'path'])
            ->map(fn (Content $content): SearchResult => SearchResult::make(
                value: $content->title,
                label: "{$content->title} — {$content->path}",
            )->withData('path', $content->path))
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
