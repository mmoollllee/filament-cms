<x-filament-widgets::widget>
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($stats as $stat)
            @if ($stat['url'])
                <a
                    href="{{ $stat['url'] }}"
                    class="group rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-white/5"
                >
            @else
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            @endif

                @if ($stat['icon'])
                    <div class="mb-2">
                        <x-filament::icon :icon="$stat['icon']" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    </div>
                @endif

                <div class="text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ $stat['value'] }}
                </div>

                <div class="mt-1 flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $stat['label'] }}
                    @if ($stat['url'])
                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 opacity-0 transition group-hover:opacity-100" />
                    @endif
                </div>

                @if ($stat['description'])
                    <div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                        {{ $stat['description'] }}
                    </div>
                @endif

            @if ($stat['url'])
                </a>
            @else
                </div>
            @endif
        @endforeach
    </div>
</x-filament-widgets::widget>
