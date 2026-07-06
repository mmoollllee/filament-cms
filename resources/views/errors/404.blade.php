@php
    /**
     * Tenant-branded 404 page. Rendered by Mmoollllee\Cms\Exceptions\NotFoundRenderer with an
     * explicit $tenant (no dependency on an app view composer). After delivery, the inline script
     * asks /_resolve404 to auto-resolve the path: a confident match redirects immediately, a
     * medium match shows "Meinten Sie?" links.
     *
     * Extensible layout: a site may override individual parts (rather than copy the whole file) by
     * providing a `{site_key}.errors.404` view that `@extends('cms::errors.404')` and only fills the
     * seams below. The NotFoundRenderer prefers that per-site view when it exists. Seams:
     *   - @stack('cms-error-head')       inject extra <head> CSS/meta (cascades over the defaults)
     *   - @section('cms-error-logo')     replace the logo block
     *   - @yield('cms-error-heading')    the H1 headline (default: "Seite nicht gefunden")
     *   - @section('cms-error-message')  the sub-headline paragraph
     *   - @section('cms-error-actions')  the call-to-action link(s)
     *
     * @var \Mmoollllee\Cms\Contracts\Tenant|null $tenant
     * @var string $requestedPath
     * @var string $homeUrl
     * @var string $resolveUrl
     */
    $logoUrl = $tenant?->resolvedMainLogoUrl();
    $primaryColor = $tenant?->resolvedPrimaryColor() ?? '#005f4e';
    $displayName = $tenant?->displayName() ?? config('app.name', 'CMS');
    $requestedPath = $requestedPath ?? request()->path();
    $homeUrl = $homeUrl ?? '/';
    $resolveUrl = $resolveUrl ?? url('/_resolve404');
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Seite nicht gefunden – {{ $displayName }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand: {{ $primaryColor }}; }
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 2rem;
            text-align: center;
            gap: 0.25rem;
        }
        .error-logo { height: 3rem; width: auto; margin-bottom: 1.5rem; opacity: 0.75; }
        .error-code {
            font-size: clamp(4.5rem, 12vw, 9rem);
            font-weight: 900;
            line-height: 1;
            letter-spacing: -0.04em;
            color: var(--brand);
            opacity: 0.3;
        }
        .error-title { margin-top: 0.5rem; font-size: clamp(1.3rem, 3vw, 1.75rem); font-weight: 700; }
        .error-message { margin-top: 0.75rem; font-size: 1rem; color: #94a3b8; max-width: 34rem; line-height: 1.5; }
        .error-message code { font-family: ui-monospace, SFMono-Regular, monospace; color: #cbd5e1; word-break: break-all; }
        .error-suggest { margin-top: 1.75rem; width: 100%; max-width: 30rem; }
        .error-suggest p { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 0.75rem; }
        .error-suggest ul { list-style: none; display: flex; flex-direction: column; gap: 0.5rem; }
        .error-suggest a {
            display: flex; flex-direction: column; gap: 0.15rem; padding: 0.7rem 1rem; border-radius: 0.6rem;
            background: rgba(148,163,184,0.08); border: 1px solid rgba(148,163,184,0.15);
            color: #e2e8f0; text-decoration: none; font-weight: 500; transition: border-color .2s, background .2s;
        }
        .error-suggest a:hover { background: rgba(148,163,184,0.16); border-color: var(--brand); }
        .error-suggest .suggest-title { font-weight: 600; }
        .error-suggest .suggest-path { font-size: 0.8rem; color: #94a3b8; font-family: ui-monospace, SFMono-Regular, monospace; }
        .error-link {
            display: inline-flex; margin-top: 2rem; padding: 0.75rem 2rem;
            background: var(--brand); color: #fff; text-decoration: none;
            border-radius: 0.75rem; font-weight: 600; font-size: 0.95rem; transition: opacity .2s;
        }
        .error-link:hover { opacity: 0.85; }
        [hidden] { display: none !important; }
    </style>
    @stack('cms-error-head')
</head>
<body>
    @section('cms-error-logo')
        @if (filled($logoUrl))
            <img src="{{ $logoUrl }}" alt="{{ $displayName }}" class="error-logo">
        @endif
    @show

    <div class="error-code">404</div>
    <h1 class="error-title">@yield('cms-error-heading', 'Seite nicht gefunden')</h1>
    @section('cms-error-message')
        <p class="error-message">
            Die Seite <code>{{ $requestedPath }}</code> gibt es nicht (mehr).
        </p>
    @show

    <nav class="error-suggest" id="cms-suggest" hidden aria-live="polite">
        <p>Meinten Sie?</p>
        <ul id="cms-suggest-list"></ul>
    </nav>

    @section('cms-error-actions')
        <a href="{{ $homeUrl }}" class="error-link">Zurück zur Startseite</a>
    @show

    <script>
        (function () {
            var resolveUrl = @json($resolveUrl);
            var path = @json($requestedPath);

            fetch(resolveUrl + '?path=' + encodeURIComponent(path), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) {
                    if (!data) { return; }

                    if (data.redirect) {
                        window.location.replace(data.redirect);
                        return;
                    }

                    var suggestions = data.suggestions || [];
                    if (!suggestions.length) { return; }

                    var list = document.getElementById('cms-suggest-list');
                    suggestions.forEach(function (item) {
                        if (!item || !item.path) { return; }
                        var li = document.createElement('li');
                        var link = document.createElement('a');
                        link.href = item.path;

                        var label = (item.trail && item.trail.length) ? item.trail.join(' › ') : item.title;

                        if (label) {
                            var title = document.createElement('span');
                            title.className = 'suggest-title';
                            title.textContent = label;
                            var path = document.createElement('span');
                            path.className = 'suggest-path';
                            path.textContent = item.path;
                            link.appendChild(title);
                            link.appendChild(path);
                        } else {
                            link.textContent = item.path;
                        }

                        li.appendChild(link);
                        list.appendChild(li);
                    });
                    document.getElementById('cms-suggest').hidden = false;
                })
                .catch(function () { /* silent: the 404 page already stands on its own */ });
        })();
    </script>
</body>
</html>
