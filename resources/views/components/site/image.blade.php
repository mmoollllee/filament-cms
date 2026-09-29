{{-- Responsive image for any stored media ref (library item id or legacy path).
     Library images get srcset/sizes from the pre-generated responsive
     conversions, their intrinsic size (the caller's width/height win) and
     their blurred placeholder as a background until the file has loaded;
     legacy paths degrade to a plain <img>. --}}
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
    $placeholderStyle = MediaUrlResolver::placeholderStyle(MediaUrlResolver::placeholder($media));
    $dimensions = MediaUrlResolver::dimensions($media) ?? [];

    // A template that loads an image eagerly has picked its hero itself; a media block
    // further down must not claim the high fetch priority on top of it.
    if ($loading === 'eager') {
        \Mmoollllee\Cms\Support\Media\MediaLoadingPriority::forgo();
    }
@endphp

@if (filled($src))
    <img
        src="{{ $src }}"
        @if (filled($srcset)) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
        alt="{{ $alt ?? '' }}"
        loading="{{ $loading }}"
        decoding="async"
        {{ $attributes->merge(array_filter([...$dimensions, 'style' => $placeholderStyle])) }}
    >
@endif
