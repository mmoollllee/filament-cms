@props([
    'blocks' => [],
    'content' => null,
    'tenant' => null,
    'navigationContext' => null,
    'emptyHeading' => null,
    'emptyText' => null,
])

@php
    $blocks = $blocks instanceof \Illuminate\Support\Collection ? $blocks->all() : ($blocks ?: ($content?->blocks ?? []));
    // Anonymous components do not inherit the caller's locals, so resolve the tenant
    // ourselves: explicit prop → the content's tenant → the request-scoped singleton.
    // Child blocks (listing, section) need it to query and propagate the tenant.
    $tenant = $tenant ?? $content?->tenant ?? app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();
@endphp

@if ($blocks === [])
    <x-site.card tag="article" {{ $attributes->class(['p-6 md:p-7 rounded-panel prose grid gap-3']) }}>
        @if (filled($emptyHeading))
            <h2 class="text-heading leading-tight text-slate-900">{{ $emptyHeading }}</h2>
        @endif

        <p class="muted">{{ $emptyText ?? 'Inhalt folgt.' }}</p>
    </x-site.card>
@else
    @foreach ($blocks as $block)
        @php
            $type = data_get($block, 'type');
            $data = data_get($block, 'data', []);
        @endphp

        @continue(!($data['active'] ?? true))
        @continue(! view()->exists("blocks::{$type}.{$type}") && ! view()->exists("blocks::{$type}"))

        @php
            // anchor_id in block data takes precedence over navigationContext system
            $anchorId = filled($data['anchor_id'] ?? null)
                ? $data['anchor_id']
                : data_get($navigationContext, 'blockAnchors.'.$loop->index.'.id');
        @endphp

        <x-dynamic-component
            :component="'block::' . $type"
            :data="$data"
            :tenant="$tenant"
            :content="$content"
            :anchor-id="$anchorId"
            :navigation-context="$navigationContext"
        />
    @endforeach
@endif
