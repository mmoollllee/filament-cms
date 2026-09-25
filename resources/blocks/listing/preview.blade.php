@php
    $presetIds = array_map('intval', array_filter((array) ($layout_preset_ids ?? [])));
    $presetClasses = $presetIds
        ? \Mmoollllee\Cms\Models\LayoutPreset::whereIn('id', $presetIds)->pluck('classes')->implode(' ')
        : '';
    $tenant = app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();

    $blueprint = filled($content_type ?? null)
        ? app(\Mmoollllee\Cms\Sites\ContentBlueprintRegistry::class)->find($content_type, $tenant?->site_key)
        : null;
    $contentTypeLabel = $blueprint?->label() ?? (filled($content_type ?? null) ? $content_type : '—');

    // Deep-link to the resource that manages this content type. The listing lists
    // the type tenant-wide (not children of the current record), so the link is
    // NOT parent-scoped. Absent when no resource manages the type or the user may
    // not open it.
    $manageLink = filled($content_type ?? null)
        ? \Mmoollllee\Cms\Filament\Support\ManagementLinks::forContentType($content_type, $tenant)
        : null;
@endphp
<div class="grid gap-4">
    <div class="prose grid gap-2 {{ $presetClasses }}">
        @if (filled($title ?? null))
            <h3>{{ $title }}</h3>
        @endif
        <p class="text-sm text-current/60">
            Listing: {{ $contentTypeLabel }}
        </p>
    </div>

    @if ($manageLink)
        <div class="fi-cms-manage-links">
            <x-cms::manage-link :link="$manageLink" />
        </div>
    @endif
</div>
