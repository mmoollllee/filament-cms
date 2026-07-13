{{-- Brand-agnostic favicon fallback: a single icon link from the tenant's
     favicon_path (branding inheritance included), nothing when unset. Apps
     with full icon sets (sizes, apple-touch icons, manifest) override this via
     their own resources/views/components/site/favicon.blade.php.
     Usage: <x-site.favicon /> --}}
@props(['tenant' => null])

@php
    // Anonymous components don't inherit the caller's locals, so resolve the tenant
    // ourselves (explicit prop → request-scoped singleton) instead of assuming it.
    $tenant ??= app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();

    $faviconUrl = $tenant->resolvedFaviconUrl();
@endphp

@if (filled($faviconUrl))
    <link rel="icon" href="{{ $faviconUrl }}">
@endif
