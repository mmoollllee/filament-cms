{{-- Builder preview for the media block.

     Three things this does NOT do the way the frontend view does:

     - it renders a preview-sized conversion, not the original, so opening a page
       in the panel does not pull full-size uploads (a hero video is megabytes),
     - a video shows as a still image — the block's poster, else the item's own
       `thumb` — because a <video> tile without a poster paints black in most
       browsers, and the fetch is wasted either way,
     - it carries its own baseline height. Layout presets are written for the
       frontend section grid and mostly set max-h/row-span, neither of which gives
       a standalone preview any height to work with. --}}
@php
    use Mmoollllee\Cms\Support\Media\MediaUrlResolver;

    $ref = $media_path ?? null;
    $isVideo = MediaUrlResolver::isVideo($ref);

    // url() falls back to the original when the conversion was never generated
    // (queued, disabled, or a legacy path), so this is safe on every install.
    $mediaUrl = MediaUrlResolver::url($ref, $isVideo ? null : '400');

    // conversionUrl(), not url($ref, 'thumb'): the latter's fallback to the
    // original would put the video file into an <img src>.
    $stillUrl = $isVideo
        ? (MediaUrlResolver::url($poster_path ?? null) ?? MediaUrlResolver::conversionUrl($ref, 'thumb'))
        : null;

    $presetIds = array_map('intval', array_filter((array) ($layout_preset_ids ?? [])));
    $presetClasses = $presetIds
        ? \Mmoollllee\Cms\Models\LayoutPreset::whereIn('id', $presetIds)->pluck('classes')->implode(' ')
        : '';

    $alt = $title ?? 'Media';
@endphp
<div class="relative flex min-h-40 items-stretch justify-stretch overflow-hidden {{ $presetClasses }}">
    @if ($mediaUrl)
        @if ($isVideo && $stillUrl)
            <img class="block h-full w-full object-cover" src="{{ $stillUrl }}" alt="{{ $alt }}">
            <span class="absolute bottom-2 left-2 rounded bg-black/60 px-2 py-0.5 text-xs font-medium text-white">
                Video
            </span>
        @elseif ($isVideo)
            {{-- No still to show: no poster set, and no `thumb` generated — the item
                 predates the conversion, its queue has not run, or there is no
                 reachable ffmpeg. The #t=0.1 media fragment makes browsers that
                 support it paint the first frame instead of a black box. --}}
            <video class="pointer-events-none block min-h-full min-w-full object-cover" muted playsinline preload="metadata">
                <source src="{{ $mediaUrl }}#t=0.1" type="video/{{ pathinfo(parse_url($mediaUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) }}">
            </video>
        @else
            <img class="block h-full w-full object-cover" src="{{ $mediaUrl }}" alt="{{ $alt }}">
        @endif
    @else
        <div class="grid w-full place-items-center p-6 text-sm text-gray-400">
            Kein Medium ausgewählt
        </div>
    @endif
</div>
