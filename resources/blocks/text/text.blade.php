{{-- Block: text — Neutral text block for headings, copy, lists and rich content --}}
@props([
    'data' => [],
    'tenant' => null,
    'content' => null,
    'anchorId' => null,
    'navigationContext' => null,
    'layoutPreset' => '',
])

@php
    $eyebrow = $data['eyebrow'] ?? null;
    $title = $data['title'] ?? null;
    $heading = $data['heading'] ?? null;
    $resolvedTag = in_array($heading, ['h1', 'h2', 'h3']) ? $heading : 'h2';
    $renderedContent = \Mmoollllee\Cms\Support\Content\RichText::render(data_get($data, 'content'));
@endphp

<div {{ $attributes->class(['anim prose grid gap-4']) }} @if (filled($anchorId)) id="{{ $anchorId }}" @endif>
    @if (filled($eyebrow) || filled($title))
        <x-site.section-header
            :eyebrow="$eyebrow"
            :title="$title"
            :heading="$resolvedTag"
        />
    @endif

    @if (filled($renderedContent))
        <div class="richtext">
            {!! $renderedContent !!}
        </div>
    @endif
</div>
