{{--
    filament-cms demo — onepager shell (tenant B, localhost).

    Demonstrates the sections feature: every root `default.section` content is one
    scroll section of a single page; each section's own path serves this same shell
    (see OnepagerShellController). This demo shell renders all sections statically —
    a production app (like the münch jobs site) would add the Alpine `siteOnepager`
    component for lazy loading + scroll-synced navigation on top.
--}}
@php
    $primary = $tenant->resolvedPrimaryColor();
    $navLinks = $sectionLinks ?? [];
    $legal = $legalLinks ?? [];
    $social = $socialLinks ?? [];
    $currentPath = $currentContent->resolvedPath() ?? '/';
    $anchorFor = fn (string $path): string => trim($path, '/') === '' ? 'start' : str_replace('/', '-', trim($path, '/'));
@endphp
<!DOCTYPE html>
<html lang="{{ $tenant->default_locale ?? 'de' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $currentContent->title }} — {{ $tenant->displayName() }}</title>
    @if (filled($d = $tenant->resolvedDefaultSeoDescription()))<meta name="description" content="{{ $d }}">@endif
    @include('demo.styles')
</head>
<body>
    <header class="site-nav">
        <div class="container">
            <a href="/" class="brand"><span class="dot">▲</span>{{ $tenant->displayName() }}</a>
            <nav class="nav-links">
                @foreach ($navLinks as $item)
                    <a href="#{{ $anchorFor($item['path']) }}" @class(['active' => rtrim($item['href'], '/') === rtrim($currentPath, '/')])>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <main>
        <section class="hero hero--home">
            <div class="container">
                @if (filled($claim = $tenant->resolvedBrandClaim()))<p class="eyebrow">{{ $claim }}</p>@endif
                <h1>{{ $tenant->displayName() }}</h1>
                <p class="subtitle">Onepager-Demo: Diese Seite besteht aus <strong>{{ count($sectionsPayload) }} Sektionen</strong> (Content-Typ <code>default.section</code>) — jede Sektion hat ihren eigenen Pfad, alle Pfade rendern dieselbe Seite.</p>
            </div>
        </section>

        @foreach ($sectionsPayload as $section)
            <section
                id="{{ $anchorFor($section['path']) }}"
                class="onepager-demo-section"
                data-path="{{ $section['path'] }}"
            >
                <div class="container">
                    @include('content.page', [
                        'content' => $section['content'],
                        'navigationContext' => $section['navigation'],
                    ])
                </div>
            </section>
        @endforeach
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

    <script>
        // Scroll-synced navigation: highlight the menu link of the section in view
        // (the production shells do this inside the Alpine `siteOnepager` component,
        // which also rewrites the URL to the section's path).
        const links = new Map(
            Array.from(document.querySelectorAll('.nav-links a[href^="#"]'))
                .map((link) => [link.getAttribute('href').slice(1), link]),
        );

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (! entry.isIntersecting) return;

                links.forEach((link) => link.classList.remove('active'));
                links.get(entry.target.id)?.classList.add('active');
            });
        }, { rootMargin: '-40% 0px -55% 0px' });

        document.querySelectorAll('.onepager-demo-section').forEach((section) => observer.observe(section));
    </script>
    {{-- Draft preview indicator — every app shell should include this once. --}}
    @include('cms::partials.preview-badge')
</body>
</html>
