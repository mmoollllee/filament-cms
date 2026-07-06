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
    use Mmoollllee\Cms\Support\AssetUrlResolver;

    $mediaPath = is_array($data['media_path'] ?? null)
        ? array_values($data['media_path'])[0] ?? null
        : ($data['media_path'] ?? null);
    $mediaUrl = AssetUrlResolver::resolve($mediaPath);

    $posterPath = is_array($data['poster_path'] ?? null)
        ? array_values($data['poster_path'])[0] ?? null
        : ($data['poster_path'] ?? null);
    $posterUrl = AssetUrlResolver::resolve($posterPath);

    $mediaAlt = filled($data['media_alt'] ?? '') ? $data['media_alt'] : ($data['title'] ?? '');

    $presetIds = array_map('intval', array_filter((array) ($data['layout_preset_ids'] ?? [])));
    $layoutPreset = app(\Mmoollllee\Cms\Support\Content\LayoutPresetResolver::class)->resolve($presetIds);
@endphp

@if ($mediaUrl)
    <x-site.media-item
        :src="$mediaUrl"
        :alt="$mediaAlt"
        :poster="$posterUrl"
        {{ $attributes->class(['anim min-h-[inherit]']) }}
        :id="$anchorId"
    />
@endif
