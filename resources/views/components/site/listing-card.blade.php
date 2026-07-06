{{-- Minimal default <x-site.listing-card>. Consuming apps override with their
     own resources/views/components/site/listing-card.blade.php (fallback only). --}}
@props([
    'content' => null,
])

@php
    $href = $content?->resolvedPath() ?? '#';
    $title = $content?->title ?? '';
@endphp

<a
    href="{{ $href }}"
    {{ $attributes->class(['listing-card']) }}
    style="display:block;padding:1rem;border:1px solid #e2e8f0;border-radius:.5rem;text-decoration:none;color:inherit;"
>
    <strong>{{ $title }}</strong>
</a>
