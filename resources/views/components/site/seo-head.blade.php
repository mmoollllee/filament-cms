{{-- Brand-agnostic SEO head: canonical URL, robots, Open Graph, Twitter Card,
     JSON-LD Organization + BreadcrumbList — everything derives from tenant
     branding fields and the SeoFields meta.* overrides (seo_title,
     seo_description, noindex, og_image_url).
     Project-specific rules plug in WITHOUT copying this view — register
     SeoHead::noindexWhen() / SeoHead::addSchema() in a service provider
     (see docs/CUSTOMIZATION.md, "SEO head"). A full app-side view override
     remains the last resort.
     Usage: <x-site.seo-head :content="$content ?? null" :breadcrumbs="$initialBreadcrumbs ?? []" /> --}}
@props(['content' => null, 'tenant' => null, 'breadcrumbs' => []])

@php
    // Anonymous components don't inherit the caller's locals, so resolve the tenant
    // ourselves (explicit prop → request-scoped singleton) instead of assuming it.
    // Nullable: a tenant-less render (error page on an unresolved domain) emits nothing.
    $tenant ??= app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();
@endphp

@if ($tenant !== null)
    @php
        $pageTitle = \Mmoollllee\Cms\Support\Seo\SeoHead::title($content, $tenant);
        $pageDescription = data_get($content, 'meta.seo_description')
            ?: $tenant->resolvedDefaultSeoDescription();
        $ogImageUrl = data_get($content, 'meta.og_image_url')
            ?: $tenant->resolvedDefaultOgImageUrl();
        $canonicalUrl = request()->url();
        $logoUrl = $tenant->resolvedMainLogoUrl();
        $isNoindex = \Mmoollllee\Cms\Support\Seo\SeoHead::isNoindex($content, $tenant);

        // Build the JSON-LD arrays inside this @php block: a literal '@context' in
        // template text (echo expressions included) is compiled as Blade's @context
        // directive (Laravel 12) and would replace the key with PHP code. @php
        // blocks are protected from directive compilation.
        $organizationSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $tenant->displayName(),
            'url' => url('/'),
            'logo' => $logoUrl,
        ]);

        $breadcrumbListSchema = $breadcrumbs === [] ? null : [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($breadcrumbs)->map(fn (array $crumb, int $i) => array_filter([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['label'],
                'item' => filled($crumb['path'] ?? null) ? url($crumb['path']) : null,
            ]))->values()->all(),
        ];

        $additionalSchemas = \Mmoollllee\Cms\Support\Seo\SeoHead::schemas($content, $tenant);

        // JSON_HEX_TAG escapes <> so a '</script>' in editor-controlled values
        // (titles, breadcrumb labels) cannot break out of the script tag.
        $jsonLdFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;
    @endphp

    @if ($isNoindex)
        <meta name="robots" content="noindex, follow">
    @endif

    <link rel="canonical" href="{{ $canonicalUrl }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if (filled($pageDescription))
        <meta property="og:description" content="{{ $pageDescription }}">
    @endif
    @if (filled($ogImageUrl))
        <meta property="og:image" content="{{ $ogImageUrl }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
    @endif

    <meta name="twitter:card" content="{{ filled($ogImageUrl) ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    @if (filled($pageDescription))
        <meta name="twitter:description" content="{{ $pageDescription }}">
    @endif
    @if (filled($ogImageUrl))
        <meta name="twitter:image" content="{{ $ogImageUrl }}">
    @endif

    <script type="application/ld+json">{!! json_encode($organizationSchema, $jsonLdFlags) !!}</script>

    @if ($breadcrumbListSchema !== null)
        <script type="application/ld+json">{!! json_encode($breadcrumbListSchema, $jsonLdFlags) !!}</script>
    @endif

    @foreach ($additionalSchemas as $schema)
        <script type="application/ld+json">{!! json_encode($schema, $jsonLdFlags) !!}</script>
    @endforeach
@endif
