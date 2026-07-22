{{-- Block: media — Einzelnes Bild oder Video mit optionalem Overlay --}}
@props([
    'data' => [],
    'tenant' => null,
    'content' => null,
    'anchorId' => null,
    'navigationContext' => null,
    'layoutPreset' => '',
])

@php
    use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

    $mediaRef = $data['media_path'] ?? null;
    $mediaUrl = MediaUrlResolver::url($mediaRef);
    $isVideo = MediaUrlResolver::isVideo($mediaRef);

    $posterRef = $data['poster_path'] ?? null;
    $posterUrl = MediaUrlResolver::url($posterRef);

    // Per-use override → central alt text from the library → block title.
    $mediaAlt = filled($data['media_alt'] ?? '')
        ? $data['media_alt']
        : (MediaUrlResolver::alt($mediaRef) ?? ($data['title'] ?? ''));

    $srcset = $isVideo ? null : MediaUrlResolver::srcset($mediaRef);

    $presetIds = array_map('intval', array_filter((array) ($data['layout_preset_ids'] ?? [])));
    $layoutPreset = app(\Mmoollllee\Cms\Support\Content\LayoutPresetResolver::class)->resolve($presetIds);
@endphp

@if ($mediaUrl)
    <x-site.media-item
        :src="$mediaUrl"
        :alt="$mediaAlt"
        :poster="$posterUrl"
        {{ $attributes->class(['anim min-h-[inherit]'])->merge(array_filter([
            'srcset' => $srcset,
            'sizes' => $srcset ? '100vw' : null,
            'loading' => $isVideo ? null : 'lazy',
            'decoding' => $isVideo ? null : 'async',
        ])) }}
        :id="$anchorId"
    />
@endif
