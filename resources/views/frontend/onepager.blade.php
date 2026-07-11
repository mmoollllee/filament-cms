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
                        <span class="text-sm">wird geladen&hellip;</span>
                    </div>
                @endif
                </div>
        @endforeach

        {{-- Scroll hints (large screens): pills labeled with the previous/next section
             title. siteOnepager drives them per scroll frame: the opacity follows the
             free gap between pill zone and nearest section content, the pill rides
             sticky on the middle of the empty band between two contents
             (--scroll-hint-shift) and dissolves toward the section handover point.
             Hover/focus holds interactivity and forces full visibility; below the
             interactive threshold the pill is disabled (no clicks, no focus). Hidden
             via opacity — never display — so the resting zone stays measurable, and
             the translate must never be CSS-transitioned (the ride tracks the scroll
             1:1 and the resting position is derived from the measured rect). --}}
        @foreach (['up' => 'top-24', 'down' => 'bottom-8'] as $direction => $position)
            <button
                type="button"
                data-scroll-hint="{{ $direction }}"
                x-cloak
                x-ref="scrollHint{{ ucfirst($direction) }}"
                x-bind:style="{ '--scroll-hint-opacity': scrollHintOpacity('{{ $direction }}'), '--scroll-hint-shift': scrollHintShift('{{ $direction }}') + 'px' }"
                x-bind:disabled="! scrollHintInteractive('{{ $direction }}')"
                x-on:click="goToAdjacentSection('{{ $direction }}')"
                x-on:mouseenter="holdScrollHint('{{ $direction }}', true)"
                x-on:mouseleave="holdScrollHint('{{ $direction }}', false)"
                x-on:focus="holdScrollHint('{{ $direction }}', true)"
                x-on:blur="holdScrollHint('{{ $direction }}', false)"
                class="scroll-hint {{ $position }} fixed left-1/2 z-50 hidden h-10 -translate-x-1/2 translate-y-[var(--scroll-hint-shift,0px)] cursor-pointer items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 text-xs font-semibold uppercase tracking-wider text-white/70 opacity-[var(--scroll-hint-opacity,0)] backdrop-blur-sm transition-[opacity,background-color,border-color,color] duration-150 hover:border-white/30 hover:bg-white/20 hover:text-white hover:opacity-100 focus-visible:opacity-100 disabled:pointer-events-none lg:flex"
            >
                @if ($direction === 'up')
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6" /></svg>
                @endif
                <span x-text="scrollHintTargetLabel('{{ $direction }}')"></span>
                @if ($direction === 'down')
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                @endif
            </button>
        @endforeach

        <x-site.footer :legal-links="$legalLinks" />
    </main>

    @push('scripts')
        @livewireScriptConfig
    @endpush
</x-site.layout>
