{{-- Responsive image for any stored media ref (library item id or legacy path).
     Library images get srcset/sizes from the pre-generated responsive
     conversions; legacy paths degrade to a plain <img>. --}}
@props([
    'media' => null,
    'alt' => null,
    'sizes' => '100vw',
    'conversion' => null,
    'loading' => 'lazy',
])

@php
    use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

    $src = MediaUrlResolver::url($media, $conversion);
    $srcset = MediaUrlResolver::srcset($media);
    $alt ??= MediaUrlResolver::alt($media);
@endphp

@if (filled($src))
    <img
        src="{{ $src }}"
        @if (filled($srcset)) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
        alt="{{ $alt ?? '' }}"
        loading="{{ $loading }}"
        decoding="async"
        {{ $attributes }}
    >
@endif
