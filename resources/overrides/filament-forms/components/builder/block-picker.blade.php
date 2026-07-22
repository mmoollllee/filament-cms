{{--
    cms:override — vendored copy of filament/forms resources/views/components/builder/block-picker.blade.php
    (baseline: filament/filament v5.7.1). Shadows the vendor view via prependNamespace()
    in CmsServiceProvider — the cms builder view renders the picker as this Blade
    component, so the namespace lookup still applies even though vendor's own builder
    (toEmbeddedHtml) inlines the picker HTML in PHP since 5.7. Drift is guarded by
    tests/Feature/FilamentViewOverrideDriftTest.php.

    Divergence from vendor (marked below): the picker accepts the builder's state path and
    appends an "Aus Zwischenablage einfügen" entry that pastes a block copied with the
    "Block kopieren" item action (BaseBuilderBlock::copyBlockAction()). Server half:
    Concerns\PastesBuilderBlocks::pasteBuilderBlock() on the panel's Create/Edit pages.
--}}
@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\Enums\GridDirection;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
@endphp

@props([
    'action',
    'actionAlignment' => null,
    'afterItem' => null,
    'blocks',
    'columns' => null,
    'key',
    // cms: paste target — the builder's state path (passed by the overridden builder view)
    'statePath' => null,
    'trigger',
    'width' => null,
])

<x-filament::dropdown
    :placement="
        match ($actionAlignment) {
            Alignment::Start, Alignment::Left => 'bottom-start',
            Alignment::End, Alignment::Right => 'bottom-end',
            default => null,
        }
    "
    shift
    :width="$width"
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

    <x-filament::dropdown.list>
        <div
            {{ (new FilamentComponentAttributeBag)->grid($columns, GridDirection::Column) }}
        >
            @foreach ($blocks as $block)
                @php
                    $blockIcon = $block->getIcon();

                    $wireClickActionArguments = ['block' => $block->getName()];

                    if (filled($afterItem)) {
                        $wireClickActionArguments['afterItem'] = $afterItem;
                    }

                    $wireClickActionArguments = \Illuminate\Support\Js::from($wireClickActionArguments);

                    $wireClickAction = "mountAction('{$action->getName()}', {$wireClickActionArguments}, { schemaComponent: '{$key}' })";
                @endphp

                <x-filament::dropdown.list.item
                    :icon="$blockIcon"
                    x-on:click="close"
                    :wire:click="$wireClickAction"
                >
                    {{ $block->getLabel() }}
                </x-filament::dropdown.list.item>
            @endforeach
        </div>

        {{-- cms:start block-picker paste — reads the copied block from the clipboard
             (localStorage fallback) and calls pasteBuilderBlock() on the page. --}}
        @if (filled($statePath))
            @php
                $pasteStatePath = \Illuminate\Support\Js::from($statePath)->toHtml();
                $pasteAfterItem = \Illuminate\Support\Js::from($afterItem)->toHtml();
                $pasteClickHandler = <<<JS
                    close();
                    let payload = null;
                    try {
                        const text = await navigator.clipboard.readText();
                        if (text) payload = JSON.parse(text);
                    } catch(e) {
                        try {
                            const stored = localStorage.getItem('filament_builder_clipboard');
                            if (stored) payload = JSON.parse(stored);
                        } catch(e2) {}
                    }
                    if (payload && payload.type && payload.data) {
                        await \$wire.pasteBuilderBlock(
                            {$pasteStatePath},
                            JSON.stringify(payload),
                            {$pasteAfterItem}
                        );
                    } else {
                        new FilamentNotification()
                            .title('Kein Block in der Zwischenablage')
                            .warning()
                            .send();
                    }
                JS;
            @endphp

            <x-filament::dropdown.list.item
                icon="heroicon-o-clipboard-document-check"
                :x-on:click="$pasteClickHandler"
            >
                Aus Zwischenablage einfügen
            </x-filament::dropdown.list.item>
        @endif
        {{-- cms:end --}}
    </x-filament::dropdown.list>
</x-filament::dropdown>
