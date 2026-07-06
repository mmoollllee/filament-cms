{{-- Block: listing — Generic content listing for any tenant-registered content type --}}
@props([
    'data' => [],
    'tenant' => null,
    'content' => null,
    'anchorId' => null,
    'navigationContext' => null,
    'layoutPreset' => '',
])

@php
    $contentType = $data['content_type'] ?? null;

    // Any per-type filtering (e.g. hiding "unavailable" items) is the consuming
    // app's concern — expose it via the content model's visibleContents() scope.
    $items = ($tenant && filled($contentType))
        ? $tenant->visibleContents(request()->user(), $contentType)->values()
        : collect();

    $wrapperPresetIds = array_map('intval', array_filter((array) ($data['wrapper_preset_ids'] ?? [])));
    $wrapperClasses = app(\Mmoollllee\Cms\Support\Content\LayoutPresetResolver::class)->resolve($wrapperPresetIds);
    $isSpread = blank($wrapperClasses);
@endphp

@if ($items->isNotEmpty())
    @if ($isSpread)
        @foreach ($items as $item)
            <x-site.listing-card :content="$item" class="anim" />
        @endforeach
    @else
        <div {{ $attributes->class(['anim', $wrapperClasses]) }} @if (filled($anchorId)) id="{{ $anchorId }}" @endif>
            @foreach ($items as $item)
                <x-site.listing-card :content="$item" />
            @endforeach
        </div>
    @endif
@endif
