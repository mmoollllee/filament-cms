@props(['tag' => 'div'])

@php
    $tag = in_array($tag, ['article', 'section', 'aside', 'footer', 'nav', 'div']) ? $tag : 'div';
@endphp

<{{ $tag }} {{ $attributes->class(['card']) }}>
    {{ $slot }}
</{{ $tag }}>
