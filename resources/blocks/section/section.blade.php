{{-- Block: section — Grid-Sektion mit konfigurierbarem Layout via LayoutPresets --}}
@props([
    'data' => [],
    'tenant' => null,
    'content' => null,
    'anchorId' => null,
    'navigationContext' => null,
    'layoutPreset' => '',
])

@php
    use Mmoollllee\Cms\Support\AssetUrlResolver;
    use Mmoollllee\Cms\Support\Content\LayoutPresetResolver;
    use Mmoollllee\Cms\Support\Content\RichText;

    $resolver = app(LayoutPresetResolver::class);

    $title = $data['title'] ?? null;
    $heading = $data['heading'] ?? null;
    $eyebrow = $data['eyebrow'] ?? null;
    $childBlocks = (array) ($data['blocks'] ?? []);

    $presetIds = array_map('intval', array_filter((array) ($data['layout_preset_ids'] ?? [])));
    $presetClasses = $resolver->resolve($presetIds);

    $backgroundImageUrl = AssetUrlResolver::resolve($data['background_image'] ?? null);
    $hasBackground = filled($backgroundImageUrl);

    $sectionClasses = implode(' ', array_filter([
        'grid stagger',
        str_contains($presetClasses, 'gap-') ? '' : 'gap-6',
        $presetClasses,
        // Positioning context is only needed for the absolute background layer.
        $hasBackground ? 'relative' : '',
    ]));

    // The header renders whenever it has content; the optional header preset
    // only styles it (width/alignment). No preset ≠ no header.
    $headerPresetIds = array_map('intval', array_filter((array) ($data['header_preset_ids'] ?? [])));
    $headerClasses = $resolver->resolve($headerPresetIds);
    $sectionContent = RichText::render(data_get($data, 'content'));
    $hasHeader = filled($title) || filled($eyebrow) || filled($sectionContent);
@endphp

<section {{ $attributes->class([$sectionClasses]) }} @if (filled($anchorId)) id="{{ $anchorId }}" @endif>
    @if ($hasBackground)
        <div class="pointer-events-none absolute inset-0 z-0 overflow-hidden rounded-section">
            <img class="h-full w-full object-cover" src="{{ $backgroundImageUrl }}" alt="" aria-hidden="true">
            <div class="absolute inset-0 bg-surface/80"></div>
        </div>
    @endif

    @if ($hasHeader)
        <header @class(['prose grid gap-4', $headerClasses, 'relative z-10' => $hasBackground])>
            <x-site.section-header :eyebrow="$eyebrow" :title="$title" :heading="$heading" />

            @if (filled($sectionContent))
                <div class="richtext">
                    {!! $sectionContent !!}
                </div>
            @endif
        </header>
    @endif

    @foreach ($childBlocks as $child)
        @php
            $childType = data_get($child, 'type');
            $childData = data_get($child, 'data', []);
        @endphp

        @continue(!($childData['active'] ?? true))

        @php
            $childAnchorId = $childData['anchor_id'] ?? null;

            $childPresetIds = array_map('intval', array_filter((array) ($childData['layout_preset_ids'] ?? [])));
            $childClasses = $resolver->resolve($childPresetIds);
        @endphp

        <x-dynamic-component
            :component="'block::' . $childType"
            :data="$childData"
            :tenant="$tenant"
            :content="$content"
            :navigation-context="$navigationContext"
            :anchor-id="$childAnchorId"
            :layout-preset="$childClasses"
            @class([$childClasses, 'relative z-10' => $hasBackground])
        />
    @endforeach
</section>
