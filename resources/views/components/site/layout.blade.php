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
    {{-- Test suites without a Vite build stub this via withoutVite() (package
         TestCase + app Pest.php, non-browser suites); Pest browser tests keep
         the real bundle and fail loudly when no manifest was built. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @spamprotectKey
</head>
<body class="site min-h-screen antialiased text-white" style="{{ $siteBrandingStyle }}">
    {{ $slot }}

    {{-- Optional GDPR consent layer: rendered only when the project installs
         mmoollllee/filament-consent-control (which pulls in laravel-consent-control).
         The CMS engine wires the banner + runtime boot; the consent config and
         styling live in the project, not here (multi-tenant friendly). --}}
    @if (class_exists(\Mmoollllee\LaravelConsentControl\LaravelConsentControlServiceProvider::class))
        <x-consent-control-banner />
        {{-- Boot config only: the project bundles the runtime JS + overlay CSS itself
             (Vite imports from the vendor dist) and Tailwind styles the banner Blade
             via @source — see the CMS README's "GDPR consent" section. --}}
        <x-consent-control-scripts :assets="false" />
    @endif
    @stack('scripts')
</body>
</html>
