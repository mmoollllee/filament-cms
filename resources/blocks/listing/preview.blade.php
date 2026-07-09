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

    // Deep-link to the Filament resource that manages this content type. The listing
    // lists the type tenant-wide (not children of the current record), so the link is
    // NOT parent-scoped. Absent when no resource manages the type, or the URL cannot
    // be built (e.g. rendered outside a panel context).
    $manageUrl = null;
    $manageLabel = null;

    if (filled($content_type ?? null)) {
        $resourceClass = app(\Mmoollllee\Cms\Support\Content\ContentResourceLocator::class)
            ->resolve($content_type, $tenant);

        // Only offer the deep-link if the user may actually open that resource —
        // otherwise the button would lead to a 403.
        if ($resourceClass !== null && $resourceClass::canAccess()) {
            try {
                // Scope the index to this type: the catch-all resource manages many
                // types, so an unscoped index would contradict the type-specific label.
                $manageUrl = $resourceClass::getUrl('index', ['type' => $content_type]);
                $manageLabel = ($blueprint?->pluralLabel() ?? $contentTypeLabel).' verwalten';
            } catch (\Throwable) {
                $manageUrl = null;
            }
        }
    }
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

    @if ($manageUrl)
        {{-- A real navigation target inside an otherwise click-dead preview:
             resources/css/builder.css re-enables pointer events for
             .fi-cms-listing-manage, and x-on:click.stop keeps the click from also
             triggering the preview's inline-edit toggle (so it navigates instead). --}}
        <a
            href="{{ $manageUrl }}"
            x-on:click.stop
            class="fi-cms-listing-manage"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122" />
            </svg>
            {{ $manageLabel }}
        </a>
    @endif
</div>
