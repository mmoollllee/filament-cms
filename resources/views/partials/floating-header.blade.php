{{--
    Brand-agnostic floating header (fallback only: an app view at
    resources/views/partials/floating-header.blade.php takes precedence).
    Self-contained: inline SVG icons + the tenant's uploaded logo — no
    app-registered blade-icon sets required. Binds ONLY core members of
    siteOnepager/siteChildNavigation (no measuring, no Alpine-store
    bindings), so a consumer app without JS overrides renders it error-free.
    Brand behavior (progress bar, depth meter, measured breadcrumb fitting,
    logo evade) is app territory; the muench-tiefbau.de repo is the
    reference implementation.
--}}
@php
    $logoUrl = $tenant->resolvedMainLogoUrl();
@endphp
<div class="site-header fixed inset-edge z-70 h-0 [--site-edge-space:.75em] xl:[--site-edge-space:1.5em]" @if ($isOnepager) x-cloak @endif>
    <a
        href="/"
        class="fixed block overflow-hidden transition-all logo-link"
        @if ($isOnepager) x-bind:class="{ 'pointer-events-none opacity-0': !showLogo() }" @endif
    >
        @if (filled($logoUrl))
            <img src="{{ $logoUrl }}" alt="{{ $tenant->displayName() }}" class="logo h-full w-auto object-contain">
        @else
            <span class="logo block leading-none font-black tracking-wide text-white uppercase">{{ $tenant->displayName() }}</span>
        @endif
    </a>

    @php
        $initialNavigationContext = $initialNavigationContext ?? $navigationContext ?? [];
    @endphp

    <div
        @class(['flex flex-col items-end h-0', 'max-w-full' => $isOnepager])
        @unless ($isOnepager)
            x-data="siteChildNavigation($el, {{ \Illuminate\Support\Js::from($initialNavigationContext) }})"
            x-on:keydown.escape.window="closeMenu()"
            x-on:scroll.window.passive="onScroll()"
            x-on:hashchange.window="handlePopstate()"
            x-on:popstate.window="handlePopstate()"
        @endunless
    >
        {{-- Breadcrumbs + Indicator + Menu bar --}}
        <div class="relative flex items-start gap-0"
             style="background: color-mix(in oklab, var(--color-surface) 88%, black 12%);"
        >
            @include('partials.header-breadcrumbs')

            <div class="overflow-y-auto transition-all nav-menu" x-bind:class="menuOpen ? 'w-[calc(100vw-var(--site-edge-space)*2)] sm:w-[min(var(--site-flyout-width),calc(100vw-var(--site-edge-space)*2))]' : ''" x-bind:data-open="menuOpen ? 'true' : 'false'">
                <div class="relative z-10 flex items-stretch justify-end h-12 nav-menu-trigger" x-bind:data-open="menuOpen ? 'true' : 'false'">
                    <span
                        class="nav-indicator relative block min-w-0 mr-2 ml-3 leading-12 font-black uppercase text-white text-xs md:text-base"
                        data-role="header-indicator"
                        x-show="!menuOpen"
                    >
                        <span class="nav-indicator-text block max-w-40 truncate whitespace-nowrap md:max-w-64" x-text="currentIndicatorLabel()">{{ $initialNavigationContext['indicatorLabel'] ?? '' }}</span>
                    </span>

                    <button
                        type="button"
                        class="relative z-20 inline-flex items-center justify-center h-full p-2 transition-all cursor-pointer nav-menu-btn aspect-square"
                        x-bind:data-open="menuOpen ? 'true' : 'false'"
                        x-bind:aria-expanded="menuOpen ? 'true' : 'false'"
                        x-bind:aria-label="menuOpen ? {{ \Illuminate\Support\Js::from(__('cms::frontend.menu_close')) }} : {{ \Illuminate\Support\Js::from(__('cms::frontend.menu_open')) }}"
                        x-on:click.stop="toggleMenu()"
                    >
                        <svg x-show="!menuOpen" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" /></svg>
                        <svg x-show="menuOpen" x-cloak class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                    </button>
                </div>

                @include('partials.header-flyout')
            </div>
        </div>

        <div
            class="fixed inset-0 z-[-1] bg-black/20 backdrop-blur transition-all duration-300"
            x-cloak
            x-show="menuOpen"
            x-transition:enter-start="opacity-0 backdrop-blur-0"
            x-transition:leave-end="opacity-0 backdrop-blur-0"
            x-on:click="closeMenu()"
        ></div>
    </div>
</div>
