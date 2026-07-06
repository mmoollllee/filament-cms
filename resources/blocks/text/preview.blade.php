@php
    $eyebrow = $eyebrow ?? null;
    $title = $title ?? null;
    $heading = $heading ?? null;
    $resolvedTag = in_array($heading, ['h1', 'h2', 'h3']) ? $heading : 'h2';
    $renderedContent = \Mmoollllee\Cms\Support\Content\RichText::render($content ?? null);

    $presetIds = array_map('intval', array_filter((array) ($layout_preset_ids ?? [])));
    $presetClasses = $presetIds
        ? \Mmoollllee\Cms\Models\LayoutPreset::whereIn('id', $presetIds)->pluck('classes')->implode(' ')
        : '';
@endphp
<div class="prose grid gap-4 {{ $presetClasses }}">
    @if (filled($eyebrow) || filled($title))
        <div class="grid gap-2">
            @if (filled($eyebrow))
                <p class="eyebrow">{!! \Mmoollllee\Cms\Support\Shortcodes::render(e($eyebrow)) !!}</p>
            @endif
            @if (filled($title))
                <{{ $resolvedTag }}>{{ $title }}</{{ $resolvedTag }}>
            @endif
        </div>
    @endif

    @if (filled($renderedContent))
        {{-- Links are click-dead inside previews via builder.css (.fi-fo-builder-item-preview a). --}}
        <div class="richtext">
            {!! $renderedContent !!}
        </div>
    @endif
</div>
