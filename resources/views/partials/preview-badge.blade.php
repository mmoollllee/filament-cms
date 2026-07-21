{{--
    Floating "Vorschau" badge — shown while draft preview mode is active
    (cms_preview_active(), see PreviewMode). Deliberately inline-styled: it must
    render identically in every consumer app regardless of its Tailwind build.
    The icon resolves from blade-heroicons (a Filament dependency, registered
    app-wide) — same glyph as the panel's Vorschau action, no frontend build.
    Apps with a custom layout include it via @include('cms::partials.preview-badge').
--}}
@if (cms_preview_active())
    <div style="position:fixed;bottom:1rem;left:1rem;z-index:9999;display:flex;align-items:center;gap:.625rem;background:#b45309;color:#fff;font-size:.8125rem;font-weight:600;line-height:1;padding:.5rem .875rem;border-radius:9999px;box-shadow:0 2px 10px rgb(0 0 0 / .35);">
        {{ svg('heroicon-o-eye', '', ['style' => 'width:1rem;height:1rem;flex:none;', 'aria-hidden' => 'true']) }}
        <span>Vorschau: Entwürfe sichtbar</span>
        {{-- fullUrlWithQuery: keep the page's other query params when leaving. --}}
        <a href="{{ request()->fullUrlWithQuery(['preview' => 0]) }}" style="color:#fff;text-decoration:underline;text-underline-offset:2px;font-weight:700;">Beenden</a>
    </div>
@endif
