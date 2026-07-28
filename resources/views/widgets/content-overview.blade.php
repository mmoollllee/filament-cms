@php
    $cardClasses = 'flex flex-col rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
@endphp

<x-filament-widgets::widget>
    @if ($summary)
        <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
            {{ $summary }}
        </p>
    @endif

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($stats as $stat)
            @if ($stat['url'])
                <a
                    href="{{ $stat['url'] }}"
                    class="group {{ $cardClasses }} transition hover:bg-gray-50 dark:hover:bg-white/5"
                >
            @else
                <div class="{{ $cardClasses }}">
            @endif

                <div class="flex items-center justify-between gap-2">
                    <span class="text-2xl font-semibold text-gray-950 dark:text-white">
                        {{ $stat['value'] }}
                    </span>

                    @if ($stat['icon'])
                        <x-filament::icon
                            :icon="$stat['icon']"
                            class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500"
                        />
                    @endif
                </div>

                <div class="mt-1 flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                    <span class="min-w-0 truncate">{{ $stat['label'] }}</span>

                    @if ($stat['url'])
                        <x-filament::icon
                            icon="heroicon-m-arrow-right"
                            class="h-3.5 w-3.5 shrink-0 opacity-0 transition group-hover:opacity-100"
                        />
                    @endif
                </div>

            @if ($stat['url'])
                </a>
            @else
                </div>
            @endif
        @endforeach
    </div>
</x-filament-widgets::widget>
