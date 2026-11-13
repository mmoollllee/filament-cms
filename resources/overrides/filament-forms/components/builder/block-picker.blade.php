{{--
    cms:override — vendored copy of filament/forms resources/views/components/builder/block-picker.blade.php
    (baseline: filament/filament v5.11.3). Shadows the vendor view via prependNamespace()
    in CmsServiceProvider — the cms builder view renders the picker as this Blade
    component, so the namespace lookup still applies even though vendor's own builder
    (toEmbeddedHtml) inlines the picker HTML in PHP since 5.7. Drift is guarded by
    tests/Feature/FilamentViewOverrideDriftTest.php.

    Divergence from vendor (marked below): the picker takes the builder's paste action and
    appends an "Aus Zwischenablage" entry that pastes a block copied with the "Block kopieren"
    item action (BaseBuilderBlock::copyBlockAction()). The action is the PasteItemAction of
    mmoollllee/filament-builder-extensions, registered on every CMS builder by
    BlockBuilder::make(): the browser reads the clipboard, then mounts it with what it found;
    between two blocks it pastes after the one above (the builder view hands the picker the
    action invoked with that item).

    The picker also heads its blocks with their groups, as Filament's PHP picker does and its
    Blade view does not: the builder view hands it each block's group (blockGroups).
--}}
@props([
    'action',
    'actionAlignment' => null,
    'afterItem' => null,
    // cms: the blocks' groups, block name => heading (passed by the overridden builder view)
    'blockGroups' => [],
    'blocks',
    'columns' => null,
    'key',
    'noSearchResultsMessage' => null,
    // cms: the builder's PasteItemAction (passed by the overridden builder view)
    'pasteAction' => null,
    'searchable' => false,
    'searchDebounce' => 0,
    'searchPrompt' => null,
    'trigger',
    'width' => null,
])

@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\Enums\GridDirection;
    use Filament\Support\Enums\Width;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Illuminate\Contracts\Support\Htmlable;
    use Illuminate\Support\Js;
    use Illuminate\Support\Str;

    $placement = match ($actionAlignment) {
        Alignment::Start, Alignment::Left => 'bottom-start',
        Alignment::End, Alignment::Right => 'bottom-end',
        default => null,
    };

    if (is_string($width)) {
        $width = Width::tryFrom($width) ?? $width;
    }

    $widthClass = ($width instanceof Width)
        ? "fi-width-{$width->value}"
        : (is_string($width) ? $width : null);

    $listAttributes = $searchable
        ? new FilamentComponentAttributeBag([
            'x-data' => 'blockPicker()',
        ])
        : new FilamentComponentAttributeBag;
@endphp

<x-filament::dropdown
    :menu="! $searchable"
    :placement="$placement"
    shift
    :width="$width"
    :wire:key="$key . '.' . $action->getName() . '.' . $afterItem . '.block-picker.' . md5(serialize([$widthClass, $placement, filled($blocks), $searchable]))"
    :attributes="
        \Filament\Support\prepare_inherited_attributes(
            $attributes->class([
                'fi-fo-builder-block-picker',
                ($actionAlignment instanceof Alignment) ? ('fi-align-' . $actionAlignment->value) : $actionAlignment,
            ]),
        )
    "
>
    <x-slot name="trigger">
        {{ $trigger }}
    </x-slot>

    @if (filled($blocks))
        <x-filament::dropdown.list
            :attributes="\Filament\Support\prepare_inherited_attributes($listAttributes)"
        >
            @if ($searchable)
                <div class="fi-fo-builder-block-picker-search-ctn">
                    <x-filament::input
                        type="text"
                        x-ref="searchInput"
                        x-on:keydown.enter.prevent=""
                        :attributes="
                            \Filament\Support\prepare_inherited_attributes(
                                new FilamentComponentAttributeBag([
                                    'aria-label' => e($searchPrompt),
                                    'placeholder' => e($searchPrompt),
                                    'x-model.debounce.' . $searchDebounce => 'search',
                                ]),
                            )
                        "
                    />
                </div>
            @endif

            {{-- cms:start block groups — the markup of Filament's PHP picker (generateBlockPickerHtml()),
                 which the vendor view lacks: ungrouped blocks first, then each group under its
                 heading; the groups flow down the columns, a search hides a group without a match.
                 Without groups, one grid of all blocks, as there. The items are the vendor view's. --}}
            @php
                $groupedBlocks = ['' => []];

                foreach ($blocks as $block) {
                    $groupedBlocks[$blockGroups[$block->getName()] ?? ''][] = $block;
                }

                $groupedBlocks = array_filter($groupedBlocks);
                $hasBlockGroups = (array_keys($groupedBlocks) !== ['']) && filled($groupedBlocks);
            @endphp

            <div
                {{ $hasBlockGroups ? (new FilamentComponentAttributeBag)->grid($columns, GridDirection::Column)->class(['fi-fo-builder-block-picker-groups']) : new FilamentComponentAttributeBag }}
            >
                @foreach ($groupedBlocks as $group => $groupBlocks)
                <div
                    {{ ($hasBlockGroups ? new FilamentComponentAttributeBag : (new FilamentComponentAttributeBag)->grid($columns, GridDirection::Column))->class(['fi-fo-builder-block-picker-group' => $hasBlockGroups]) }}
                    @if ($hasBlockGroups && $searchable) x-show="isGroupVisible($el)" @endif
                    @if (filled($group)) role="group" aria-label="{{ $group }}" @endif
                >
                    @if (filled($group))
                        <div class="fi-fo-builder-block-picker-group-label">{{ $group }}</div>
                    @endif

                @foreach ($groupBlocks as $block)
                    @php
                        $blockIcon = $block->getIcon();
                        $blockLabel = $block->getLabel();

                        $blockSearchLabel = null;

                        if ($searchable) {
                            $blockSearchLabel = $blockLabel;

                            if ($blockSearchLabel instanceof Htmlable) {
                                $blockSearchLabel = html_entity_decode(strip_tags($blockSearchLabel->toHtml()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            }

                            $blockSearchLabel = Str::lower($blockSearchLabel);
                        }

                        $wireClickActionArguments = ['block' => $block->getName()];

                        if (filled($afterItem)) {
                            $wireClickActionArguments['afterItem'] = $afterItem;
                        }

                        $wireClickActionArguments = Js::from($wireClickActionArguments);

                        $wireClickAction = "mountAction('{$action->getName()}', {$wireClickActionArguments}, { schemaComponent: '{$key}' })";
                    @endphp

                    <x-filament::dropdown.list.item
                        :icon="$blockIcon"
                        :role="$searchable ? 'button' : 'menuitem'"
                        :tabindex="$searchable ? 0 : -1"
                        x-on:click="close"
                        :wire:click="$wireClickAction"
                        :wire:key="$searchable ? md5($wireClickAction . $blockSearchLabel) : null"
                        :data-block-label="$blockSearchLabel"
                        :x-show="$searchable ? 'isBlockVisible($el)' : null"
                    >
                        {{ $blockLabel }}
                    </x-filament::dropdown.list.item>
                @endforeach
                </div>
                @endforeach
            </div>
            {{-- cms:end --}}

            @if ($searchable)
                <div
                    x-cloak
                    x-show="hasNoSearchResults"
                    role="status"
                    aria-live="polite"
                    class="fi-fo-builder-block-picker-no-search-results-message"
                >
                    {{ $noSearchResultsMessage }}
                </div>
            @endif

            {{-- cms:start block-picker paste — the package's PasteItemAction: its click handler reads
                 the clipboard (localStorage fallback) and mounts the action with what it found. The
                 label stays short enough for the picker's width; the title carries the full one. A
                 search leaves it standing: it is no block to search for. --}}
            @if ($pasteAction?->isVisible())
                <x-filament::dropdown.list.item
                    :icon="$pasteAction->getIcon()"
                    :role="$searchable ? 'button' : 'menuitem'"
                    :tabindex="$searchable ? 0 : -1"
                    :title="$pasteAction->getTooltip()"
                    :x-load-js="json_encode([\Mmoollllee\FilamentBuilderExtensions\FilamentBuilderExtensionsServiceProvider::scriptSrc()], JSON_UNESCAPED_SLASHES)"
                    :x-on:click="'close(); '.$pasteAction->getAlpineClickHandler()"
                >
                    {{ $pasteAction->getLabel() }}
                </x-filament::dropdown.list.item>
            @endif
            {{-- cms:end --}}
        </x-filament::dropdown.list>
    @endif
</x-filament::dropdown>
