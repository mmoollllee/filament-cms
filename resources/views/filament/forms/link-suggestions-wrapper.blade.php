@php
    use Filament\Support\Facades\FilamentAsset;
@endphp

@props([
    'field' => null,
    'hasInlineLabel' => null,
])

{{--
    Styled replacement for the defstudio searchable-input field wrapper.

    The Alpine component (`searchableInput`, shipped by the vendor package)
    stays in charge of fetching suggestions, keyboard navigation and selection;
    only the dropdown markup differs: two-line entries rendering the Content
    title + path from each suggestion's `data` (single-line `label` fallback
    when a suggestion carries no data). Styles: resources/css/builder.css
    (`.cms-link-suggest-*`).
--}}
@if ($field->isSearchEnabled())
    <x-filament-forms::field-wrapper
        :field="$field"
        :has-inline-label="$hasInlineLabel"
        class="cms-link-suggest"

        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('filament-searchable-input', 'defstudio/filament-searchable-input') }}"
        x-data="searchableInput({
            key: '{{ $field->getKey() }}',
            statePath: '{{ $field->getStatePath() }}',
        })"

        x-on:click.away="suggestions = []"

        x-on:keyup.prevent.enter=""
        x-on:keyup.prevent.esc=""
        x-on:keyup.prevent.up=""
        x-on:keyup.prevent.down=""
        x-on:keyup="$event.key != 'Enter' && $event.key != 'ArrowDown' && $event.key != 'ArrowUp' && $event.key != 'Escape' && refresh_suggestions"

        x-on:keydown.prevent.enter="set(suggestions[selected_suggestion])"
        x-on:keydown.prevent.esc="suggestions = []"
        x-on:keydown.prevent.up="previous_suggestion()"
        x-on:keydown.prevent.down="next_suggestion()"

        x-on:keydown.tab="suggestions = []"
    >
        {{ $slot }}

        <div
            x-cloak
            x-show="suggestions.length > 0"
            class="cms-link-suggest-dropdown"
        >
            <ul
                class="cms-link-suggest-list"
                wire:loading.class.delay="cms-link-suggest-loading"
            >
                <template x-for="(suggestion, index) in suggestions" :key="index">
                    <li
                        class="cms-link-suggest-item"
                        x-bind:class="{ 'cms-link-suggest-item-active': selected_suggestion === index }"
                        x-effect="selected_suggestion === index && suggestions.length && $el.scrollIntoView({ block: 'nearest' })"
                        x-on:click="set(suggestion)"
                    >
                        <x-filament::icon icon="heroicon-m-link" class="cms-link-suggest-item-icon" />

                        <span class="cms-link-suggest-item-text">
                            <span
                                class="cms-link-suggest-item-title"
                                x-text="suggestion.data?.title || suggestion.label"
                            ></span>

                            <template x-if="suggestion.data?.path">
                                <span
                                    class="cms-link-suggest-item-path"
                                    x-text="suggestion.data.path"
                                ></span>
                            </template>
                        </span>
                    </li>
                </template>
            </ul>
        </div>
    </x-filament-forms::field-wrapper>
@else
    <x-filament-forms::field-wrapper
        :field="$field"
        :has-inline-label="$hasInlineLabel"
    >{{ $slot }}</x-filament-forms::field-wrapper>
@endif
