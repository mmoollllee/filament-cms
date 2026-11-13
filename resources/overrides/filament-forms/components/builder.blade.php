{{--
    cms:override — Blade equivalent of Filament\Forms\Components\Builder::toEmbeddedHtml()
    (baseline: filament/filament v5.11.3). Since 5.7 the Builder has NO vendor Blade view
    anymore (HasEmbeddedView renders PHP-side); this view re-enters the classic render
    path because BlockBuilder::make() sets it explicitly via
    ->view('filament-forms::components.builder') — a component with an explicit view skips
    toEmbeddedHtml(), and the prependNamespace() registration makes THIS file win the
    namespace lookup.

    Every divergence from the vendor-equivalent markup is wrapped in "cms:start" /
    "cms:end" marker comments. (Blade comments do NOT nest — never write literal comment
    tokens inside this header, they would terminate it early and leak the rest as page
    output.) To re-vendor after a Filament update: translate the changes in
    Builder::toEmbeddedHtml() / generateBlockPickerHtml() into this view and re-apply the
    marked blocks (tests/Feature/FilamentViewOverrideDriftTest.php hashes those method
    sources and fails whenever they change, so drift is never silent). The markup Filament
    makes in protected helpers (the reordering component and its screen-reader texts) is
    asked of the field itself, so it stays Filament's.

    cms features carried by this view:
      1. inline preview editing — with ->blockPreviews() (non-interactive), clicking a preview
         swaps it for the item's form in place ("Fertig" closes it) instead of an edit modal.
      2. inactive blocks — items with data.active = false get a pill in the header that dims
         the row (builder.css) and reactivates it on click (state half:
         BaseBuilderBlock::optionHiddenFields()).
      3. option badges — the row header shows what the "Block-Optionen" dialog holds
         (layout presets, header layout, background image, anchor) as small badges, so
         options that live in the dialog stay visible (Filament\Support\BlockOptionBadges).
      4. block-picker paste — the builder's paste action (registered by BlockBuilder::make()) is
         passed through to the block picker, whose "Aus Zwischenablage" entry runs it — between
         two blocks invoked with the block above (see block-picker.blade.php).
      5. block groups — Filament's PHP picker lists blocks under their groups (blocks() given
         named arrays, BlockBuilder::grouped()); its Blade picker gets them flat. This view
         hands the picker each block's group, read with the protected getBlockGroup().

    Dragging blocks between builders needs no view: it comes from
    mmoollllee/filament-builder-extensions (transferGroup()), which hooks into vendor markup
    this view keeps as it is; so do copy and paste themselves (CopyItemAction, PasteItemAction).
--}}
@php
    use Filament\Actions\Action;
    use Filament\Forms\Components\Builder\Block;
    use Illuminate\Contracts\Support\Htmlable;

    $fieldWrapperView = $getFieldWrapperView();
    // Vendor filters before counting so first/last flags agree with the loop.
    $items = array_filter(
        $getItems(),
        static fn ($item): bool => $item->getParentComponent() instanceof Block,
    );
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
    $id = $getId();

    $blockLabelHeadingTag = $getHeadingTag();
    $isBlockLabelTruncated = $isBlockLabelTruncated();
    $labelBetweenItems = $getLabelBetweenItems();

    $hasBlockLabels = $hasBlockLabels();
    $hasBlockIcons = $hasBlockIcons();
    $hasBlockNumbers = $hasBlockNumbers();
    $hasBlockHeaders = $hasBlockHeaders();

    // What generateBlockPickerHtml() hands the block picker.
    $isSearchable = $isSearchable();
    $searchPrompt = $isSearchable ? $getSearchPrompt() : null;
    $searchDebounce = $getSearchDebounce();
    $noSearchResultsMessage = $isSearchable ? $getNoSearchResultsMessage() : null;

    if ($searchPrompt instanceof Htmlable) {
        $searchPrompt = html_entity_decode(strip_tags($searchPrompt->toHtml()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Filament's own helpers of toEmbeddedHtml() are protected: called on the field itself.
    $vendor = fn (string $method, mixed ...$arguments): mixed => (fn (): mixed => $this->{$method}(...$arguments))->call($field);

    // Should a Filament update rename them, the field renders itself as Filament does:
    // without the CMS's additions, previews editable in Filament's modal (BlockBuilder
    // keeps its edit action then) — never a broken page; the drift test tells us.
    $rendersCmsView = \Mmoollllee\Cms\Filament\Forms\BlockBuilder::rendersCmsView();

    // cms:start (4) block-picker paste — the builder's PasteItemAction, if it has one
    $pasteAction = $getAction(\Mmoollllee\Cms\Support\Content\Blocks\BaseBuilderBlock::PASTE_ACTION);
    // cms:end

    // cms:start (5) block groups — what generateBlockPickerHtml() reads with the protected
    // getBlockGroup(): each picker block's group, for the picker's headings. Should Filament
    // rename it, the picker lists the blocks without headings.
    $blockPickerGroups = [];

    if (method_exists($field, 'getBlockGroup')) {
        foreach ($blockPickerBlocks as $block) {
            if (filled($blockGroup = $vendor('getBlockGroup', $block))) {
                $blockPickerGroups[$block->getName()] = $blockGroup;
            }
        }
    }
    // cms:end
@endphp

@if (! $rendersCmsView)
    {!! $field->toEmbeddedHtml() !!}
@else
<x-dynamic-component :component="$fieldWrapperView" :field="$field" label-tag="div">
    <div
        {{
            $attributes
                ->merge($getExtraAttributes(), escape: false)
                ->merge($vendor('getAlpineAttributes', $items), escape: false)
                ->merge([
                    'aria-describedby' => $getAccessibilityAttributes()['aria-describedby'] ?? null,
                    'aria-labelledby' => "{$id}-label",
                    'id' => $id,
                    'role' => 'group',
                ], escape: false)
                ->class([
                    'fi-fo-builder',
                    'fi-collapsible' => $isCollapsible,
                ])
        }}
    >
        <span wire:ignore x-ref="reorderStatus" class="fi-sr-only" role="status" aria-live="polite" aria-atomic="true"></span>

        @if ($collapseAllActionIsVisible || $expandAllActionIsVisible)
            <div
                @class([
                    'fi-fo-builder-actions',
                    'fi-hidden' => count($items) < 2,
                ])
            >
                @if ($collapseAllActionIsVisible)
                    <span x-on:click="collapseAll">
                        {{ $collapseAllAction }}
                    </span>
                @endif

                @if ($expandAllActionIsVisible)
                    <span x-on:click="expandAll">
                        {{ $expandAllAction }}
                    </span>
                @endif
            </div>
        @endif

        @if (count($items))
            <ul
                x-sortable
                x-ref="reorderItems"
                data-sortable-animation-duration="{{ $getReorderAnimationDuration() }}"
                x-on:end.stop="reorder"
                class="fi-fo-builder-items"
            >
                @foreach ($items as $itemKey => $item)
                    @php
                        /** @var Block $block */
                        $block = $item->getParentComponent();

                        $visibleExtraItemActions = array_filter(
                            $extraItemActions,
                            fn (Action $action): bool => $action(['item' => $itemKey])->isVisible(),
                        );
                        $itemCloneAction = $cloneAction(['item' => $itemKey]);
                        $cloneActionIsVisible = $isCloneable && $itemCloneAction->isVisible();
                        $itemDeleteAction = $deleteAction(['item' => $itemKey]);
                        $deleteActionIsVisible = $isDeletable && $itemDeleteAction->isVisible();
                        $itemEditAction = $editAction(['item' => $itemKey]);
                        $editActionIsVisible = $hasBlockPreviews && $itemEditAction->isVisible();
                        $reorderActionIsVisible = $isReorderableWithDragAndDrop && $reorderAction->isVisible();
                        $itemMoveDownAction = $moveDownAction(['item' => $itemKey]);
                        $itemMoveDownAction->disabled($loop->last || $itemMoveDownAction->isDisabled())->extraAttributes($vendor('getReorderingActionAttributes', $itemMoveDownAction, 'down', ! $isReorderableWithButtons), merge: true);
                        $moveDownActionIsVisible = ($isReorderableWithButtons || $reorderActionIsVisible) && $itemMoveDownAction->isVisible();
                        $itemMoveUpAction = $moveUpAction(['item' => $itemKey]);
                        $itemMoveUpAction->disabled($loop->first || $itemMoveUpAction->isDisabled())->extraAttributes($vendor('getReorderingActionAttributes', $itemMoveUpAction, 'up', ! $isReorderableWithButtons), merge: true);
                        $moveUpActionIsVisible = ($isReorderableWithButtons || $reorderActionIsVisible) && $itemMoveUpAction->isVisible();
                        $hasItemHeader = $hasBlockHeaders && ($reorderActionIsVisible || $moveUpActionIsVisible || $moveDownActionIsVisible || $hasBlockIcons || $hasBlockLabels || $editActionIsVisible || $cloneActionIsVisible || $deleteActionIsVisible || $isCollapsible || $visibleExtraItemActions);

                        // cms:start (1) inline preview editing + (2) inactive blocks — per-item flags
                        $itemHasPreview = $hasBlockPreviews && $block->hasPreview();
                        $itemIsInactive = ! filter_var($item->getRawState()['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
                        // cms:end
                    @endphp

                    <li
                        wire:ignore.self
                        wire:key="{{ $item->getLivewireKey() }}.item"
                        {{-- cms:start (1) inline preview editing — preview items start in preview mode:
                             isEditing beside the state of vendor's item() component (vendor: x-data="item(…)").
                             Object.assign() keeps the component's getter and its $persist. --}}
                        x-data="Object.assign(item(@js([
                            'isCollapsed' => $isCollapsed($item),
                            'collapseKey' => $persistCollapsed ? "filament:builder:collapsed:{$key}-{$itemKey}" : null,
                        ])), { isEditing: @js(! $itemHasPreview) })"
                        {{-- cms:end --}}
                        x-on:builder-expand.window="expandFromEvent"
                        x-on:builder-collapse.window="collapseFromEvent"
                        {{-- cms:start (1) inline preview editing — Filament expands the items around a
                             validation error (vendor: x-on:expand="expand"); a preview item opens its
                             form then, or the error stays hidden behind the preview. Filament expands
                             around the first error only, so a preview item holding any other opens
                             on the form's validation-error event itself. --}}
                        x-on:expand="expand(); isEditing = true"
                        @if ($itemHasPreview)
                            x-on:form-validation-error.window="if ($event.detail.livewireId === $wire.$id) $nextTick(() => { if ($el.querySelector('[data-validation-error]')) { expand(); isEditing = true } })"
                        @endif
                        {{-- cms:end --}}
                        x-sortable-item="{{ $itemKey }}"
                        {{
                            $block->getExtraAttributeBag()
                                ->class([
                                    'fi-fo-builder-item',
                                    'fi-fo-builder-item-has-header' => $hasItemHeader,
                                ])
                        }}
                        x-bind:class="itemClasses"
                    >
                        @if ($hasItemHeader)
                            <div
                                @if ($isCollapsible)
                                    x-on:click.stop="toggleCollapsed"
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

                                        @if ($moveUpActionIsVisible)
                                            <li x-on:click.stop class="{{ $isReorderableWithButtons ? '' : 'fi-sr-only' }}">{{ $itemMoveUpAction }}</li>
                                        @endif
                                        @if ($moveDownActionIsVisible)
                                            <li x-on:click.stop class="{{ $isReorderableWithButtons ? '' : 'fi-sr-only' }}">{{ $itemMoveDownAction }}</li>
                                        @endif
                                    </ul>
                                @endif

                                @php
                                    $blockIcon = $block->getIcon();
                                @endphp

                                @if ($hasBlockIcons && filled($blockIcon))
                                    {{ \Filament\Support\generate_icon_html($blockIcon, attributes: (new \Filament\Support\View\ComponentAttributeBag)->class(['fi-fo-builder-item-header-icon'])) }}
                                @endif

                                @if ($hasBlockLabels)
                                    <{{ $blockLabelHeadingTag }}
                                        @class([
                                            'fi-fo-builder-item-header-label',
                                            'fi-truncated' => $isBlockLabelTruncated,
                                        ])
                                    >
                                        {{ $block->getLabel($item->getRawState(), $itemKey, $loop->index) }}

                                        @if ($hasBlockNumbers)
                                            {{ $loop->iteration }}
                                        @endif
                                    </{{ $blockLabelHeadingTag }}>
                                @endif

                                {{-- cms:start (2) inactive blocks — pill after the row title; hover swaps
                                     to a green "aktivieren" affordance, click reactivates in place. It
                                     also dims the row (builder.css): a class on the item element would
                                     go stale, Livewire never updates its own attributes. --}}
                                @if ($itemIsInactive)
                                    <button
                                        type="button"
                                        x-on:click.stop="$wire.set(@js($getStatePath() . '.' . $itemKey . '.data.active'), true)"
                                        class="fi-fo-builder-item-inactive-pill"
                                        title="Klicken zum Aktivieren"
                                    >
                                        <span class="fi-fo-builder-item-inactive-pill-inactive">inaktiv</span>
                                        <span class="fi-fo-builder-item-inactive-pill-activate">aktivieren</span>
                                    </button>
                                @endif
                                {{-- cms:end --}}

                                {{-- cms:start (3) option badges — right-aligned before the end actions --}}
                                @php
                                    $optionBadges = \Mmoollllee\Cms\Filament\Support\BlockOptionBadges::for((array) $item->getRawState());
                                @endphp

                                @if ($optionBadges !== [])
                                    <span class="fi-cms-block-option-badges">
                                        @foreach ($optionBadges as $optionBadge)
                                            <span class="fi-cms-block-option-badge" title="{{ $optionBadge['title'] }}">{{ $optionBadge['label'] }}</span>
                                        @endforeach
                                    </span>
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
                                                {{ $itemEditAction }}
                                            </li>
                                        @endif

                                        @if ($cloneActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $itemCloneAction }}
                                            </li>
                                        @endif

                                        @if ($deleteActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $itemDeleteAction }}
                                            </li>
                                        @endif

                                        @if ($isCollapsible)
                                            <li
                                                class="fi-fo-builder-item-header-collapsible-actions"
                                                x-on:click.stop="toggleCollapsed"
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
                                'fi-fo-builder-item-content-has-preview' => $hasBlockPreviews && $block->hasPreview(),
                            ])
                        >
                            @if ($hasBlockPreviews && $block->hasPreview())
                                @if ($hasInteractiveBlockPreviews)
                                    {{-- vendor behavior for interactive previews --}}
                                    <div
                                        @class([
                                            'fi-fo-builder-item-preview',
                                            'fi-interactive' => $hasInteractiveBlockPreviews,
                                        ])
                                    >
                                        {{ $block->renderPreview($item->getRawState()) }}
                                    </div>
                                @else
                                    {{-- cms:start (1) inline preview editing — replaces vendor's
                                         edit-overlay (which mounts the edit modal): clicking the
                                         preview swaps it for the item's schema in place.
                                         preventDefault() is what keeps links inside the static
                                         preview from navigating — skipped for a subtree that
                                         declares itself interactive (.fi-cms-preview-interactive,
                                         used by the note block), where the link IS the point and
                                         builder.css lets the pointer reach it.
                                         Styling lives in resources/css/builder.css. --}}
                                    <div
                                        x-show="! isEditing"
                                        x-on:click.stop="
                                            if ($event.target.closest('.fi-cms-preview-interactive a, .fi-cms-preview-interactive button')) return;
                                            $event.preventDefault();
                                            isEditing = true;
                                        "
                                        class="fi-fo-builder-item-preview"
                                    >
                                        {{ $block->renderPreview($item->getRawState()) }}
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
                                        {{-- cms: (4) and (5) the extra :paste-action and :block-groups attributes feed the picker's paste entry and headings --}}
                                        <x-filament-forms::builder.block-picker
                                            :action="$addBetweenAction"
                                            :after-item="$itemKey"
                                            :columns="$blockPickerColumns"
                                            :block-groups="$blockPickerGroups"
                                            :blocks="$blockPickerBlocks"
                                            :key="$key"
                                            :no-search-results-message="$noSearchResultsMessage"
                                            :paste-action="$pasteAction ? $pasteAction(['item' => $itemKey]) : null"
                                            :searchable="$isSearchable"
                                            :search-debounce="$searchDebounce"
                                            :search-prompt="$searchPrompt"
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
            {{-- cms: (4) and (5) the extra :paste-action and :block-groups attributes feed the picker's paste entry and headings --}}
            <x-filament-forms::builder.block-picker
                :action="$addAction"
                :action-alignment="$addActionAlignment"
                :block-groups="$blockPickerGroups"
                :blocks="$blockPickerBlocks"
                :columns="$blockPickerColumns"
                :key="$key"
                :no-search-results-message="$noSearchResultsMessage"
                :paste-action="$pasteAction"
                :searchable="$isSearchable"
                :search-debounce="$searchDebounce"
                :search-prompt="$searchPrompt"
                :width="$blockPickerWidth"
            >
                <x-slot name="trigger">
                    {{ $addAction }}
                </x-slot>
            </x-filament-forms::builder.block-picker>
        @endif

        {!! $vendor('getReorderingDescriptionHtml', $items) !!}
    </div>
</x-dynamic-component>
@endif
