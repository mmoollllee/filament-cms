{{-- Minimal default <x-site.media-item>. Consuming apps override with their own
     resources/views/components/site/media-item.blade.php (fallback only). --}}
@props([
    'src' => null,
    'alt' => '',
    'poster' => null,
    'id' => null,
])

@php
    $isVideo = is_string($src) && preg_match('/\.(mp4|webm|ogg|mov)$/i', $src) === 1;
@endphp

@if (filled($src))
    @if ($isVideo)
        <video controls {{ $attributes }} @if (filled($poster)) poster="{{ $poster }}" @endif @if (filled($id)) id="{{ $id }}" @endif>
            <source src="{{ $src }}">
        </video>
    @else
        <img src="{{ $src }}" alt="{{ $alt }}" {{ $attributes }} @if (filled($id)) id="{{ $id }}" @endif>
    @endif
@endif
