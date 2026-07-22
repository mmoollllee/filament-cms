@php
    use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

    $mediaUrl = MediaUrlResolver::url($media_path ?? null);
    $isVideo = MediaUrlResolver::isVideo($media_path ?? null);

    $posterUrl = MediaUrlResolver::url($poster_path ?? null);

    $presetIds = array_map('intval', array_filter((array) ($layout_preset_ids ?? [])));
    $presetClasses = $presetIds
        ? \Mmoollllee\Cms\Models\LayoutPreset::whereIn('id', $presetIds)->pluck('classes')->implode(' ')
        : '';
@endphp
<div class="relative overflow-hidden flex items-stretch justify-stretch {{ $presetClasses }}">
    @if ($mediaUrl)
        @if ($isVideo)
            {{-- Poster shows the still in the preview without autoplay; the #t=0.1 media
                 fragment makes browsers paint the first frame as a fallback when there is no poster. --}}
            <video class="block object-cover min-w-full min-h-full pointer-events-none" muted playsinline preload="metadata" @if ($posterUrl) poster="{{ $posterUrl }}" @endif>
                <source src="{{ $mediaUrl }}#t=0.1" type="video/{{ pathinfo($mediaUrl, PATHINFO_EXTENSION) }}">
            </video>
        @else
            <img class="block h-full w-full object-cover" src="{{ $mediaUrl }}" alt="{{ $title ?? 'Media' }}">
        @endif
    @else
        <div class="grid place-items-center p-6 text-sm text-gray-400 w-full">
            Kein Medium ausgewählt
        </div>
    @endif
</div>
