{{-- Block: hint — the demo's custom callout box (see /howto/custom-blocks) --}}
@props([
    'data' => [],
    'tenant' => null,
    'content' => null,
    'anchorId' => null,
    'navigationContext' => null,
    'layoutPreset' => '',
])

@php
    $tone = in_array($data['tone'] ?? null, ['info', 'success', 'warning']) ? $data['tone'] : 'info';
    $title = $data['title'] ?? null;
    $renderedContent = \Mmoollllee\Cms\Support\Content\RichText::render(data_get($data, 'content'));
@endphp

<div {{ $attributes->class(['hint', "hint-{$tone}"]) }} @if (filled($anchorId)) id="{{ $anchorId }}" @endif>
    @if (filled($title))
        <p class="hint-title">{{ $title }}</p>
    @endif

    @if (filled($renderedContent))
        <div class="richtext">
            {!! $renderedContent !!}
        </div>
    @endif
</div>
