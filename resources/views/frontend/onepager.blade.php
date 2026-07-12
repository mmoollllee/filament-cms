{{--
    Brand-agnostic onepager shell (fallback only: an app view at
    resources/views/frontend/onepager.blade.php takes precedence — publish via
    `php artisan vendor:publish --tag=cms-frontend`). Emits the section
    protocol the `siteOnepager` Alpine core binds against; visual extras
    (scroll-hint pills, hero fades, …) are app territory — layer them via
    `registerCmsFrontend(Alpine, overrides)`, see docs/CUSTOMIZATION.md §10.
--}}
<x-site.layout :content="$content ?? $currentContent" :initial-breadcrumbs="$initialBreadcrumbs">
    <main
        id="site-onepager"
        class="relative min-h-screen w-full flex flex-col gap-8"
        data-content-endpoint="{{ $contentEndpoint }}"
        x-data="siteOnepager($el)"
        x-on:click.capture="handleLinkClick($event)"
        x-on:scroll.window.passive="onScroll()"
        x-on:resize.window.passive="onResize()"
        x-on:hashchange.window="handlePopstate()"
        x-on:popstate.window="handlePopstate()"
    >
        @include('partials.floating-header', ['isOnepager' => true])

        @foreach ($sectionsPayload as $section)
            <div
                class="onepager-section flex flex-col justify-center gap-6 min-h-screen bg-surface p-page text-on-dark {{ $section['content']->resolvedLayoutPreset() }}"
                data-path="{{ $section['path'] }}"
                data-navigation='@json($section['navigation'])'
                data-loaded="{{ $section['content']->is($currentContent) ? 'true' : 'false' }}"
                data-title="{{ $section['title'] }}"
                data-label="{{ $section['label'] }}"
                @if ($section['anchor'])
                    data-anchor="{{ $section['anchor'] }}"
                @endif
            >
                @if ($section['content']->is($currentContent))
                    @include($currentContentView, [
                        'content' => $section['content'],
                        'navigationContext' => $section['navigation'],
                    ])
                @else
                    <div class="shell grid place-items-center gap-3 text-center text-white/56">
                        <span class="text-lg font-semibold tracking-wide">{{ $section['label'] }}</span>
                        <span class="text-sm">{{ __('cms::frontend.loading') }}</span>
                    </div>
                @endif
                </div>
        @endforeach

        <x-site.footer :legal-links="$legalLinks" />
    </main>

    @push('scripts')
        @livewireScriptConfig
    @endpush
</x-site.layout>
