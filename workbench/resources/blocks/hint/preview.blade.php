@php
    $tone = in_array($tone ?? null, ['info', 'success', 'warning']) ? $tone : 'info';
    $renderedContent = \Mmoollllee\Cms\Support\Content\RichText::render($content ?? null);
@endphp
<div class="rounded-lg border-l-4 p-4 {{ ['info' => 'border-sky-500 bg-sky-50', 'success' => 'border-green-500 bg-green-50', 'warning' => 'border-amber-500 bg-amber-50'][$tone] }}">
    @if (filled($title ?? null))
        <p class="font-semibold">{{ $title }}</p>
    @endif

    @if (filled($renderedContent))
        {{-- Links are click-dead inside previews via builder.css (.fi-fo-builder-item-preview a). --}}
        <div>
            {!! $renderedContent !!}
        </div>
    @endif
</div>
