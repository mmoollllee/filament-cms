{{--
    filament-cms demo — marketing/docs site shell.

    Self-contained layout (no build step): one <style> block implements a clean
    docs/marketing design AND styles every class the package's frontend block +
    <x-site.*> components emit (btn, nav-cards, listing-card, card, prose/richtext,
    the section grid utilities, and the layout-preset classes used by the seeder).
    A real consuming app would ship a Vite/Tailwind theme; this keeps the testbench
    a turnkey, styled showcase.
--}}
@php
    $primary = $tenant->resolvedPrimaryColor();
    $navLinks = $sectionLinks ?? [];
    $legal = $legalLinks ?? [];
    $social = $socialLinks ?? [];
    $hero = (array) data_get($content, 'payload.hero', []);
    $currentPath = $content->resolvedPath() ?? '/';
    $isHome = $currentPath === '/';
    $heroTitle = trim((string) ($hero['title'] ?? '')) ?: $content->title;
    $heroSubtitle = trim((string) ($hero['subtitle'] ?? ''));
    $heroEyebrow = $isHome ? $tenant->resolvedBrandClaim() : 'Dokumentation';
@endphp
<!DOCTYPE html>
<html lang="{{ $tenant->default_locale ?? 'de' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $content->title }} — {{ $tenant->displayName() }}</title>
    @if (filled($d = $tenant->resolvedDefaultSeoDescription()))<meta name="description" content="{{ $d }}">@endif
    @include('demo.styles')
</head>
<body>
    <header class="site-nav">
        <div class="container">
            <a href="/" class="brand"><span class="dot">▲</span>{{ $tenant->displayName() }}</a>
            <nav class="nav-links">
                @foreach ($navLinks as $item)
                    <a href="{{ $item['href'] }}" @class(['active' => rtrim($item['href'],'/') === rtrim($currentPath,'/')])>{{ $item['label'] }}</a>
                @endforeach
                @if (filled($hero['cta_url'] ?? null) && ! $isHome)
                    <a href="{{ $hero['cta_url'] }}" class="nav-cta">{{ $hero['cta_label'] ?? 'Los geht’s' }}</a>
                @endif
            </nav>
        </div>
    </header>

    <main>
        {{-- Breadcrumb trail from the engine's NavigationContextBuilder: parent_id
             chain first, path segments as fallback. Rendered only for NESTED pages
             (mode 'child' = ancestors + current); top-level pages get just a
             single self-crumb in 'standalone' mode, which we skip here. --}}
        @if (count($initialBreadcrumbs ?? []) > 1)
            <nav class="demo-breadcrumbs" aria-label="Breadcrumb">
                <div class="container">
                    <a href="/">Home</a>
                    @foreach ($initialBreadcrumbs as $crumb)
                        <span aria-hidden="true">›</span>
                        @if ($crumb['isCurrent'] || blank($crumb['path']))
                            <span aria-current="page">{{ $crumb['label'] }}</span>
                        @else
                            <a href="{{ $crumb['path'] }}">{{ $crumb['label'] }}</a>
                        @endif
                    @endforeach
                </div>
            </nav>
        @endif

        <section class="hero {{ $isHome ? 'hero--home' : 'hero--page' }}">
            <div class="container">
                @if (filled($heroEyebrow))<p class="eyebrow">{{ $heroEyebrow }}</p>@endif
                <h1>{{ $heroTitle }}</h1>
                @if (filled($heroSubtitle))<p class="subtitle">{{ $heroSubtitle }}</p>@endif
                @if (filled($hero['cta_url'] ?? null))
                    <div class="actions">
                        <a href="{{ $hero['cta_url'] }}" class="btn btn-primary btn-lg">{{ $hero['cta_label'] ?? 'Mehr erfahren' }}</a>
                        @if ($isHome && filled($navLinks[1]['href'] ?? null))
                            <a href="{{ $navLinks[1]['href'] }}" class="btn btn-secondary btn-lg">{{ $navLinks[1]['label'] }}</a>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        <div class="page-body">
            <div class="container">
                @include($contentView)
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            @if (! empty($legal))
                <nav class="footer-nav">
                    @foreach ($legal as $item)<a href="{{ $item['href'] }}">{{ $item['label'] }}</a>@endforeach
                </nav>
            @endif
            <div class="footer-meta">
                <span>&copy; {{ date('Y') }} {{ $tenant->resolvedSiteSetting('company_name') ?? $tenant->displayName() }} — gebaut mit <strong style="color:#fff">filament-cms</strong></span>
                @if (! empty($social))
                    <span class="footer-social">
                        @foreach ($social as $s)<a href="{{ $s['url'] }}" target="_blank" rel="noopener">{{ $s['label'] }}</a>@endforeach
                    </span>
                @endif
            </div>
        </div>
    </footer>
    {{-- Draft preview indicator — every app shell should include this once. --}}
    @include('cms::partials.preview-badge')
</body>
</html>
