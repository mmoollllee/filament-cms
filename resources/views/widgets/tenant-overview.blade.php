<x-filament-widgets::widget>
    @if($tenant)
        <div class="fi-wi-tenant-overview rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-6 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                {{-- Left: brand identity --}}
                <div class="flex min-w-0 items-center gap-4">
                    @if($logoUrl)
                        {{--
                            No shrink-0, and a capped width: tenant logos are
                            often wide wordmarks, and an unshrinkable one pushed
                            the brand name straight out of the viewport on phones.
                        --}}
                        <img
                            src="{{ $logoUrl }}"
                            alt="{{ $brandName }}"
                            class="h-10 w-auto max-w-[8rem] object-contain object-left sm:h-12 sm:max-w-[14rem]"
                        />
                    @endif
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-semibold text-gray-950 dark:text-white">
                            {{ $brandName }}
                        </h2>
                        @if($brandClaim)
                            <p class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $brandClaim }}</p>
                        @endif
                    </div>
                </div>

                {{-- Right: the one action this widget exists for --}}
                <div class="shrink-0">
                    <a
                        href="{{ $profileUrl }}"
                        class="fi-btn fi-btn-size-sm inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                    >
                        <x-filament::icon icon="heroicon-o-cog-6-tooth" class="h-4 w-4" />
                        Seiten-Einstellungen
                    </a>
                </div>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
