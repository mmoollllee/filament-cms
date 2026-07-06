@php
    $presetIds = array_map('intval', array_filter((array) ($layout_preset_ids ?? [])));
    $presetClasses = $presetIds
        ? \Mmoollllee\Cms\Models\LayoutPreset::whereIn('id', $presetIds)->pluck('classes')->implode(' ')
        : '';
    $tenant = app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();
    $contentTypeLabel = filled($content_type ?? null)
        ? (app(\Mmoollllee\Cms\Sites\ContentBlueprintRegistry::class)
            ->find($content_type, $tenant?->site_key)?->label() ?? $content_type)
        : '—';
@endphp
<div class="prose grid gap-2 {{ $presetClasses }}">
    @if (filled($title ?? null))
        <h3>{{ $title }}</h3>
    @endif
    <p class="text-sm text-current/60">
        Listing: {{ $contentTypeLabel }}
    </p>
</div>
