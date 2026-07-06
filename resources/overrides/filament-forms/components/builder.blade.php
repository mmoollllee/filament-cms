{{--
    cms:override — vendored copy of filament/forms resources/views/components/builder.blade.php
    (baseline: filament/filament v5.6.8). Registered via prependNamespace() in CmsServiceProvider,
    so it shadows the vendor view for EVERY builder in the app.

    Every divergence from the vendor file is wrapped in "cms:start" / "cms:end" marker
    comments. (Blade comments do NOT nest — never write literal comment tokens inside
    this header, they would terminate it early and leak the rest as page output.)
    To re-vendor after a Filament update: copy the new vendor file over this one and
    re-apply the marked blocks (tests/Feature/FilamentViewOverrideDriftTest.php fails
    whenever the vendor baseline changes, so drift is never silent).

    cms features carried by this view:
      1. cross-builder drag & drop — items can be dragged between builders sharing a
         `data-sortable-group` extra attribute; server half: Concerns\TransfersBuilderItems.
      2. inline preview editing — with ->blockPreviews() (non-interactive), clicking a preview
         swaps it for the item's form in place ("Fertig" closes it) instead of an edit modal.
      3. inactive blocks — items with data.active = false are dimmed and get a pill in the
         header that reactivates them on click (state half: BaseBuilderBlock::optionHiddenFields()).
      4. block-picker paste — the state path is passed through to the block picker so its
         "Aus Zwischenablage einfügen" entry can target this builder (see block-picker.blade.php).
--}}
@php
    use Filament\Actions\Action;
    use Filament\Support\Enums\Alignment;

    $fieldWrapperView = $getFieldWrapperView();
    $items = $getItems();
    $blockPickerBlocks = $getBlockPickerBlocks();
    $blockPickerColumns = $getBlockPickerColumns();
    $blockPickerWidth = $getBlockPickerWidth();
    $hasBlockPreviews = $hasBlockPreviews();
    $hasInteractiveBlockPreviews = $hasInteractiveBlockPreviews();

    $addAction = $getAction($getAddActionName());
    $addActionAlignment = $getAddActionAlignment();
    $addBetweenAction = $getAction($getAddBetweenActionName());
    $cloneAction = $getAction($getCloneActionName());
    $collapseAllAction = $getAction($getCollapseAllActionName());
    $editAction = $getAction($getEditActionName());
    $expandAllAction = $getAction($getExpandAllActionName());
    $deleteAction = $getAction($getDeleteActionName());
    $moveDownAction = $getAction($getMoveDownActionName());
    $moveUpAction = $getAction($getMoveUpActionName());
    $reorderAction = $getAction($getReorderActionName());
    $extraItemActions = $getExtraItemActions();

    $isAddable = $isAddable();
    $isCloneable = $isCloneable();
    $isCollapsible = $isCollapsible();
    $isDeletable = $isDeletable();
    $isReorderableWithButtons = $isReorderableWithButtons();
    $isReorderableWithDragAndDrop = $isReorderableWithDragAndDrop();

    $collapseAllActionIsVisible = $isCollapsible && $collapseAllAction->isVisible();
    $expandAllActionIsVisible = $isCollapsible && $expandAllAction->isVisible();
    $persistCollapsed = $shouldPersistCollapsed();

    $key = $getKey();
    $statePath = $getStatePath();

    $blockLabelHeadingTag = $getHeadingTag();
    $isBlockLabelTruncated = $isBlockLabelTruncated();
    $labelBetweenItems = $getLabelBetweenItems();

    // cms:start (1) cross-builder drag & drop — group name set via extraAttributes
    $sortableGroup = $getExtraAttributes()['data-sortable-group'] ?? null;
    // cms:end
@endphp

<x-dynamic-component :component="$fieldWrapperView" :field="$field">
    <div
        {{
            $attributes
                ->merge($getExtraAttributes(), escape: false)
                ->class([
                    'fi-fo-builder',
                    'fi-collapsible' => $isCollapsible,
                ])
        }}
    >
        @if ($collapseAllActionIsVisible || $expandAllActionIsVisible)
            <div
                @class([
                    'fi-fo-builder-actions',
                    'fi-hidden' => count($items) < 2,
                ])
            >
                @if ($collapseAllActionIsVisible)
                    <span
                        x-on:click="$dispatch('builder-collapse', '{{ $statePath }}')"
                    >
                        {{ $collapseAllAction }}
                    </span>
                @endif

                @if ($expandAllActionIsVisible)
                    <span
                        x-on:click="$dispatch('builder-expand', '{{ $statePath }}')"
                    >
                        {{ $expandAllAction }}
                    </span>
                @endif
            </div>
        @endif

        @if (count($items))
            <ul
                x-sortable
                {{-- cms:start (1) cross-builder drag & drop — join the sortable group + expose the
                     state path; a drop from another list calls transferBuilderItem() on the page
                     (Concerns\TransfersBuilderItems), a same-list drop keeps vendor's reorder. --}}
                @if ($sortableGroup) x-sortable-group="{{ $sortableGroup }}" @endif
                data-schema-component="{{ $key }}"
                data-state-path="{{ $statePath }}"
                {{-- cms:end --}}
                data-sortable-animation-duration="{{ $getReorderAnimationDuration() }}"
                x-on:end.stop="
                    {{-- cms:start (1) cross-builder drag & drop --}}
                    if ($event.from !== $event.to) {
                        const sourceItems = $event.from.sortable.toArray();
                        const targetItems = $event.to.sortable.toArray();

                        {{-- Remove SortableJS' DOM node so Livewire re-renders cleanly --}}
                        $event.item.remove();

                        $wire.call('transferBuilderItem', {
                            sourcePath: $event.from.dataset.statePath,
                            targetPath: $event.to.dataset.statePath,
                            sourceItems: sourceItems,
                            targetItems: targetItems,
                        })
                    } else {
                        $wire.mountAction(
                            'reorder',
                            { items: $event.target.sortable.toArray() },
                            { schemaComponent: '{{ $key }}' },
                        )
                    }
                    {{-- cms:end (vendor: only the mountAction('reorder', …) call) --}}
                "
                class="fi-fo-builder-items"
            >
                @php
                    $hasBlockLabels = $hasBlockLabels();
                    $hasBlockIcons = $hasBlockIcons();
                    $hasBlockNumbers = $hasBlockNumbers();
                    $hasBlockHeaders = $hasBlockHeaders();
                @endphp

                @foreach ($items as $itemKey => $item)
                    @php
                        $visibleExtraItemActions = array_filter(
                            $extraItemActions,
                            fn (Action $action): bool => $action(['item' => $itemKey])->isVisible(),
                        );
                        $cloneAction = $cloneAction(['item' => $itemKey]);
                        $cloneActionIsVisible = $isCloneable && $cloneAction->isVisible();
                        $deleteAction = $deleteAction(['item' => $itemKey]);
                        $deleteActionIsVisible = $isDeletable && $deleteAction->isVisible();
                        $editAction = $editAction(['item' => $itemKey]);
                        $editActionIsVisible = $hasBlockPreviews && $editAction->isVisible();
                        $moveDownAction = $moveDownAction(['item' => $itemKey])->disabled($loop->last);
                        $moveDownActionIsVisible = $isReorderableWithButtons && $moveDownAction->isVisible();
                        $moveUpAction = $moveUpAction(['item' => $itemKey])->disabled($loop->first);
                        $moveUpActionIsVisible = $isReorderableWithButtons && $moveUpAction->isVisible();
                        $reorderActionIsVisible = $isReorderableWithDragAndDrop && $reorderAction->isVisible();
                        $hasItemHeader = $hasBlockHeaders && ($reorderActionIsVisible || $moveUpActionIsVisible || $moveDownActionIsVisible || $hasBlockIcons || $hasBlockLabels || $editActionIsVisible || $cloneActionIsVisible || $deleteActionIsVisible || $isCollapsible || $visibleExtraItemActions);

                        // cms:start (2) inline preview editing + (3) inactive blocks — per-item flags
                        $itemHasPreview = $hasBlockPreviews && $item->getParentComponent()->hasPreview();
                        $itemIsInactive = ! filter_var($item->getRawState()['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
                        // cms:end
                    @endphp

                    <li
                        wire:ignore.self
                        wire:key="{{ $item->getLivewireKey() }}.item"
                        x-data="{
                            isCollapsed: @if ($persistCollapsed) $persist(@js($isCollapsed($item))).as(`builder-${@js($key)}-${@js($itemKey)}-isCollapsed`) @else @js($isCollapsed($item)) @endif,
                            {{-- cms:start (2) inline preview editing — preview items start in preview mode --}}
                            isEditing: @js(! $itemHasPreview),
                            {{-- cms:end --}}
                        }"
                        x-on:builder-expand.window="$event.detail === '{{ $statePath }}' && (isCollapsed = false)"
                        x-on:builder-collapse.window="$event.detail === '{{ $statePath }}' && (isCollapsed = true)"
                        x-on:expand="isCollapsed = false"
                        x-sortable-item="{{ $itemKey }}"
                        {{
                            $item->getParentComponent()->getExtraAttributeBag()
                                ->class([
                                    'fi-fo-builder-item',
                                    'fi-fo-builder-item-has-header' => $hasItemHeader,
                                    // cms:start (3) inactive blocks — mark THIS item inactive on its own
                                    // element (not via a descendant marker) so dimming never bubbles up
                                    // from an inactive child block to its active parent section.
                                    'fi-fo-builder-item-inactive' => $itemIsInactive,
                                    // cms:end
                                ])
                        }}
                        x-bind:class="{ 'fi-collapsed': isCollapsed }"
                    >
                        @if ($hasItemHeader)
                            <div
                                @if ($isCollapsible)
                                    x-on:click.stop="isCollapsed = !isCollapsed"
                                @endif
                                class="fi-fo-builder-item-header"
                            >
                                @if ($reorderActionIsVisible || $moveUpActionIsVisible || $moveDownActionIsVisible)
                                    <ul
                                        class="fi-fo-builder-item-header-start-actions"
                                    >
                                        @if ($reorderActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $reorderAction->extraAttributes(['x-sortable-handle' => true], merge: true) }}
                                            </li>
                                        @endif

                                        @if ($moveUpActionIsVisible || $moveDownActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $moveUpAction }}
                                            </li>

                                            <li x-on:click.stop>
                                                {{ $moveDownAction }}
                                            </li>
                                        @endif
                                    </ul>
                                @endif

                                @php
                                    $blockIcon = $item->getParentComponent()->getIcon($item->getRawState(), $itemKey);
                                @endphp

                                @if ($hasBlockIcons && filled($blockIcon))
                                    {{ \Filament\Support\generate_icon_html($blockIcon, attributes: (new \Illuminate\View\ComponentAttributeBag)->class(['fi-fo-builder-item-header-icon'])) }}
                                @endif

                                @if ($hasBlockLabels)
                                    <{{ $blockLabelHeadingTag }}
                                        @class([
                                            'fi-fo-builder-item-header-label',
                                            'fi-truncated' => $isBlockLabelTruncated,
                                        ])
                                    >
                                        {{ $item->getParentComponent()->getLabel($item->getRawState(), $itemKey, $loop->index) }}

                                        @if ($hasBlockNumbers)
                                            {{ $loop->iteration }}
                                        @endif
                                    </{{ $blockLabelHeadingTag }}>
                                @endif

                                {{-- cms:start (3) inactive blocks — pill after the row title; hover swaps
                                     to a green "aktivieren" affordance, click reactivates in place. --}}
                                @if ($itemIsInactive)
                                    <button
                                        type="button"
                                        x-on:click.stop="$wire.set(@js($statePath . '.' . $itemKey . '.data.active'), true)"
                                        class="fi-fo-builder-item-inactive-pill"
                                        title="Klicken zum Aktivieren"
                                    >
                                        <span class="fi-fo-builder-item-inactive-pill-inactive">inaktiv</span>
                                        <span class="fi-fo-builder-item-inactive-pill-activate">aktivieren</span>
                                    </button>
                                @endif
                                {{-- cms:end --}}

                                @if ($editActionIsVisible || $cloneActionIsVisible || $deleteActionIsVisible || $isCollapsible || $visibleExtraItemActions)
                                    <ul
                                        class="fi-fo-builder-item-header-end-actions"
                                    >
                                        @foreach ($visibleExtraItemActions as $extraItemAction)
                                            <li x-on:click.stop>
                                                {{ $extraItemAction(['item' => $itemKey]) }}
                                            </li>
                                        @endforeach

                                        @if ($editActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $editAction }}
                                            </li>
                                        @endif

                                        @if ($cloneActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $cloneAction }}
                                            </li>
                                        @endif

                                        @if ($deleteActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $deleteAction }}
                                            </li>
                                        @endif

                                        @if ($isCollapsible)
                                            <li
                                                class="fi-fo-builder-item-header-collapsible-actions"
                                                x-on:click.stop="isCollapsed = !isCollapsed"
                                            >
                                                <div
                                                    class="fi-fo-builder-item-header-collapse-action"
                                                >
                                                    {{ $getAction('collapse') }}
                                                </div>

                                                <div
                                                    class="fi-fo-builder-item-header-expand-action"
                                                >
                                                    {{ $getAction('expand') }}
                                                </div>
                                            </li>
                                        @endif
                                    </ul>
                                @endif
                            </div>
                        @endif

                        <div
                            x-show="! isCollapsed"
                            @class([
                                'fi-fo-builder-item-content',
                                'fi-fo-builder-item-content-has-preview' => $hasBlockPreviews && $item->getParentComponent()->hasPreview(),
                            ])
                        >
                            @if ($hasBlockPreviews && $item->getParentComponent()->hasPreview())
                                @if ($hasInteractiveBlockPreviews)
                                    {{-- vendor behavior for interactive previews --}}
                                    <div
                                        @class([
                                            'fi-fo-builder-item-preview',
                                            'fi-interactive' => $hasInteractiveBlockPreviews,
                                        ])
                                    >
                                        {{ $item->getParentComponent()->renderPreview($item->getRawState()) }}
                                    </div>
                                @else
                                    {{-- cms:start (2) inline preview editing — replaces vendor's
                                         edit-overlay (which mounts the edit modal): clicking the
                                         preview swaps it for the item's schema in place. .prevent
                                         keeps links inside the static preview from navigating;
                                         styling lives in resources/css/builder.css. --}}
                                    <div
                                        x-show="! isEditing"
                                        x-on:click.prevent.stop="isEditing = true"
                                        class="fi-fo-builder-item-preview"
                                    >
                                        {{ $item->getParentComponent()->renderPreview($item->getRawState()) }}
                                    </div>

                                    <div x-show="isEditing" x-cloak class="fi-fo-builder-item-inline-edit">
                                        {{ $item }}

                                        <div class="fi-fo-builder-item-inline-edit-footer">
                                            <button
                                                type="button"
                                                x-on:click.stop="isEditing = false"
                                                class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                                            >
                                                Fertig
                                            </button>
                                        </div>
                                    </div>
                                    {{-- cms:end --}}
                                @endif
                            @else
                                {{ $item }}
                            @endif
                        </div>
                    </li>

                    @if (! $loop->last)
                        @if ($isAddable && $addBetweenAction(['afterItem' => $itemKey])->isVisible())
                            <li class="fi-fo-builder-add-between-items-ctn">
                                <div class="fi-fo-builder-add-between-items">
                                    <div class="fi-fo-builder-block-picker-ctn">
                                        {{-- cms: (4) the extra :state-path attribute feeds the picker's paste entry --}}
                                        <x-filament-forms::builder.block-picker
                                            :action="$addBetweenAction"
                                            :after-item="$itemKey"
                                            :columns="$blockPickerColumns"
                                            :blocks="$blockPickerBlocks"
                                            :key="$key"
                                            :state-path="$statePath"
                                            :width="$blockPickerWidth"
                                        >
                                            <x-slot name="trigger">
                                                {{ $addBetweenAction(['afterItem' => $itemKey]) }}
                                            </x-slot>
                                        </x-filament-forms::builder.block-picker>
                                    </div>
                                </div>
                            </li>
                        @elseif (filled($labelBetweenItems))
                            <li class="fi-fo-builder-label-between-items-ctn">
                                <div
                                    class="fi-fo-builder-label-between-items-divider-before"
                                ></div>

                                <span class="fi-fo-builder-label-between-items">
                                    {{ $labelBetweenItems }}
                                </span>

                                <div
                                    class="fi-fo-builder-label-between-items-divider-after"
                                ></div>
                            </li>
                        @endif
                    @endif
                @endforeach
            </ul>
        @endif

        @if ($isAddable && $addAction->isVisible())
            {{-- cms: (4) the extra :state-path attribute feeds the picker's paste entry --}}
            <x-filament-forms::builder.block-picker
                :action="$addAction"
                :action-alignment="$addActionAlignment"
                :blocks="$blockPickerBlocks"
                :columns="$blockPickerColumns"
                :key="$key"
                :state-path="$statePath"
                :width="$blockPickerWidth"
            >
                <x-slot name="trigger">
                    {{ $addAction }}
                </x-slot>
            </x-filament-forms::builder.block-picker>
        @endif
    </div>
</x-dynamic-component>
