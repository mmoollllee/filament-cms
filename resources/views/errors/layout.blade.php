{{-- Plain, tenant-branded error page the app's `errors::` views extend.

     Lives here rather than in each app because it is written entirely against
     the engine's branding cascade (logo, primary colour, display name) and was
     otherwise copied byte-for-byte between sites. Resolvable as
     `errors.layout` through the package's registered view location, so an app
     needs its own copy only to change it.

     The pages that USE it (403, 503) cannot be fallbacks: Laravel rebuilds the
     `errors::` namespace from config('view.paths') on every error, which a
     package path is not part of — those ship as publishable templates instead
     (`--tag=cms-frontend`). --}}
@php
    $logoUrl = $tenant?->resolvedMainLogoUrl();
    $primaryColor = $tenant?->resolvedPrimaryColor() ?? '#005f4e';
    $displayName = $tenant?->displayName() ?? config('app.name', 'CMS');
@endphp
<!DOCTYPE html>
<html lang="{{ $tenant?->default_locale ?: app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') – {{ $displayName }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 2rem;
            text-align: center;
        }
        .error-code {
            font-size: clamp(5rem, 12vw, 10rem);
            font-weight: 900;
            line-height: 1;
            letter-spacing: -0.04em;
            color: {{ $primaryColor }};
            opacity: 0.25;
        }
        .error-message {
            margin-top: 1rem;
            font-size: clamp(1.1rem, 2.5vw, 1.5rem);
            font-weight: 500;
            max-width: 36rem;
            line-height: 1.5;
            color: #94a3b8;
        }
        .error-link {
            display: inline-flex;
            margin-top: 2rem;
            padding: 0.75rem 2rem;
            background: {{ $primaryColor }};
            color: white;
            text-decoration: none;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.95rem;
            transition: opacity 0.2s;
        }
        .error-link:hover { opacity: 0.85; }
        .error-logo { height: 3rem; width: auto; margin-bottom: 2rem; opacity: 0.6; }
    </style>
</head>
<body>
    @if (filled($logoUrl ?? null))
        <img src="{{ $logoUrl }}" alt="{{ $displayName }}" class="error-logo">
    @endif
    <div class="error-code">@yield('code')</div>
    <p class="error-message">@yield('message')</p>
    @hasSection('link')
        @yield('link')
    @endif
</body>
</html>
