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
    use Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriver;
    use Mmoollllee\Cms\Support\Media\MediaLoadingPriority;
    use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

    $mediaRef = $data['media_path'] ?? null;
    $mediaUrl = MediaUrlResolver::url($mediaRef);
    $isVideo = MediaUrlResolver::isVideo($mediaRef);

    // An explicit poster wins; otherwise the still the library extracted from the video
    // itself, so the block shows a frame instead of an empty box while the video buffers.
    $posterRef = $data['poster_path'] ?? null;
    $posterUrl = MediaUrlResolver::url($posterRef)
        ?? ($isVideo ? MediaUrlResolver::conversionUrl($mediaRef, CmsMediaLibraryDriver::RENDERED_CONVERSION) : null);

    // The page's first media is its hero: load it right away instead of lazily.
    $isPriority = $mediaUrl !== null && MediaLoadingPriority::claim();
    $isPriorityImage = $isPriority && ! $isVideo;

    // Per-use override → central alt text from the library → block title.
    $mediaAlt = filled($data['media_alt'] ?? '')
        ? $data['media_alt']
        : (MediaUrlResolver::alt($mediaRef) ?? ($data['title'] ?? ''));

    $srcset = $isVideo ? null : MediaUrlResolver::srcset($mediaRef);
    $placeholder = $isVideo ? null : MediaUrlResolver::placeholder($mediaRef);
    $dimensions = $isVideo ? [] : (MediaUrlResolver::dimensions($mediaRef) ?? []);

    $presetIds = array_map('intval', array_filter((array) ($data['layout_preset_ids'] ?? [])));
    $layoutPreset = app(\Mmoollllee\Cms\Support\Content\LayoutPresetResolver::class)->resolve($presetIds);
@endphp

@if ($mediaUrl)
    <x-site.media-item
        :src="$mediaUrl"
        :alt="$mediaAlt"
        :poster="$posterUrl"
        :placeholder="$placeholder"
        {{ $attributes->class(['anim min-h-[inherit]'])->merge(array_filter([
            'srcset' => $srcset,
            'sizes' => $srcset ? '100vw' : null,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'loading' => $isVideo ? null : ($isPriorityImage ? 'eager' : 'lazy'),
            'fetchpriority' => $isPriorityImage ? 'high' : null,
            'decoding' => $isVideo || $isPriorityImage ? null : 'async',
        ])) }}
        :id="$anchorId"
    />
@endif
