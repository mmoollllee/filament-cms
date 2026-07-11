<div class="site-header fixed inset-edge z-70 h-0 [--site-edge-space:.75em] xl:[--site-edge-space:1.5em]" @if ($isOnepager) x-cloak @endif>
    <a
        href="/"
        class="fixed block overflow-hidden transition-all logo-link"
        @if ($isOnepager) x-bind:class="{ 'pointer-events-none opacity-0': !showLogo() }" @endif
    >
        @svg('image-logo', 'text-white object-none h-full max-w-none logo animated')
        <span class="sr-only">{{ $tenant->displayName() }}</span>
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
            {{-- Unified scroll progress bar across entire breadcrumb+indicator area --}}
            <div
                class="absolute inset-0 bg-white/[0.07] origin-left pointer-events-none z-0 transition-transform duration-120 ease-out"
                x-show="!menuOpen"
                x-bind:style="{ transform: `scaleX(${$store.scroll.progress / 100})` }"
            >
            </div>

            {{-- Depth meter label following the progress bar edge --}}
            <div
                class="absolute inset-0 top-full h-0 pointer-events-none"
                x-cloak
                x-show="!menuOpen && $store.scroll.progress > 0"
                x-transition.opacity.duration.200ms
            >
                <span
                    class="absolute top-1 text-[0.65rem] font-mono font-semibold tabular-nums text-white/40 whitespace-nowrap -translate-x-full pr-1"
                    x-bind:style="{ left: `${$store.scroll.progress}%`, transition: 'left 120ms ease-out' }"
                    x-text="`-${$store.scroll.depthMeters} m`"
                ></span>
            </div>

            @include('partials.header-breadcrumbs')

            <div class="overflow-y-auto transition-all nav-menu" x-bind:class="menuOpen ? 'w-[calc(100vw-var(--site-edge-space)*2)] sm:w-[min(var(--site-flyout-width),calc(100vw-var(--site-edge-space)*2))]' : ''" x-bind:data-open="menuOpen ? 'true' : 'false'">
                <div class="relative z-10 flex items-stretch justify-end h-12 nav-menu-trigger" x-bind:data-open="menuOpen ? 'true' : 'false'">
                    {{-- Hidden measurer for indicator width --}}
                    <span
                        class="pointer-events-none invisible absolute h-0 overflow-visible px-1 font-black uppercase text-xs md:text-base whitespace-nowrap"
                        x-ref="indicatorMeasure"
                        aria-hidden="true"
                    >{{ $initialNavigationContext['indicatorLabel'] }}</span>

                    <span
                        class="nav-indicator relative block flex-auto min-w-0 mr-2 ml-3 leading-12 font-black uppercase text-white text-xs md:text-base"
                        data-role="header-indicator"
                        x-show="!menuOpen"
                        x-init="
                            let sizeIndicator = () => {
                                $refs.indicatorMeasure.textContent = currentIndicatorLabel();
                                $nextTick(() => {
                                    let full = $refs.indicatorMeasure.scrollWidth;
                                    let header = $el.closest('.site-header');
                                    let logo = header.querySelector('.logo-link');
                                    let available = header.clientWidth - (logo ? logo.offsetWidth : 0) - 80;
                                    let max = Math.min(224, available);
                                    $el.style.width = Math.min(full, max) + 'px';
                                    if (full > max) {
                                        $el.dataset.marquee = '';
                                        $el.style.setProperty('--marquee-offset', `-${full - max + 12}px`);
                                    } else {
                                        delete $el.dataset.marquee;
                                    }
                                });
                            };
                            window.addEventListener('resize', () => sizeIndicator());
                            $watch(() => currentIndicatorLabel(), () => sizeIndicator());
                            sizeIndicator();
                        "
                    >
                        <span class="nav-indicator-text whitespace-nowrap" x-text="currentIndicatorLabel()">{{ $initialNavigationContext['indicatorLabel'] }}</span>
                    </span>

                    <button
                        type="button"
                        class="relative z-20 inline-flex items-center justify-center h-full p-2 transition-all cursor-pointer nav-menu-btn aspect-square"
                        x-bind:data-open="menuOpen ? 'true' : 'false'"
                        x-bind:aria-expanded="menuOpen ? 'true' : 'false'"
                        x-bind:aria-label="menuOpen ? 'Menü schließen' : 'Menü öffnen'"
                        x-on:click.stop="toggleMenu()"
                    >
                        <x-icon-bars x-show="!menuOpen" class="size-5" />
                        <x-icon-chevron-right x-show="menuOpen" class="size-4" />
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
