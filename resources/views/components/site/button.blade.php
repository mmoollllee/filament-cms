@props([
    'href' => null,
    'type' => 'button',
])

@if (filled($href))
    <a href="{{ $href }}" {{ $attributes->class(['btn']) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class(['btn']) }}>
        {{ $slot }}
    </button>
@endif
