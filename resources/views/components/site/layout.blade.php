@props([
    'content' => null,
    'tenant' => null,
    'initialBreadcrumbs' => [],
])

@php
    // Anonymous components don't inherit the caller's locals, so resolve the tenant
    // ourselves (explicit prop → request-scoped singleton) instead of assuming it.
    $tenant ??= app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();
    $pageTitle = $tenant->frontendTitleFor($content ?? null);
    $pageDescription = data_get($content ?? null, 'meta.seo_description')
        ?: $tenant->resolvedDefaultSeoDescription();
    $primaryColor = $tenant->resolvedPrimaryColor();
    $siteBrandingStyle = implode(' ', [
        "--color-primary: {$primaryColor};",
        "--color-surface: color-mix(in oklab, {$primaryColor} 78%, black 22%);",
        "--color-muted-text: color-mix(in oklab, {$primaryColor} 52%, white 48%);",
        "--color-on-light: color-mix(in oklab, {$primaryColor} 82%, black 18%);",
        "--background-image-gradient-primary: radial-gradient(circle at 50% 50%, color-mix(in oklab, {$primaryColor} 68%, white 32%) 0%, color-mix(in oklab, {$primaryColor} 82%, black 18%) 331%);",
        "--background-image-gradient-bright: radial-gradient(circle at 50% 50%, color-mix(in oklab, white 92%, {$primaryColor} 8%) 0%, color-mix(in oklab, white 78%, {$primaryColor} 22%) 211%);",
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ $tenant->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>

    <x-site.favicon />

    @if (filled($pageDescription))
        <meta name="description" content="{{ $pageDescription }}">
    @endif
    <x-site.seo-head :content="$content ?? null" :breadcrumbs="$initialBreadcrumbs ?? []" />
    @if (! app()->runningUnitTests())
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @spamprotectKey
</head>
<body class="site min-h-screen antialiased text-white" style="{{ $siteBrandingStyle }}">
    {{ $slot }}

    <x-consent-control-banner />
    @stack('scripts')
</body>
</html>
