<x-filament-widgets::widget>
    @if($tenant)
        <div class="fi-wi-tenant-overview rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                {{-- Left: Tenant info --}}
                <div class="flex items-start gap-4">
                    @if($logoUrl)
                        <img
                            src="{{ $logoUrl }}"
                            alt="{{ $brandName }}"
                            class="h-12 w-auto shrink-0 object-contain"
                        />
                    @endif
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                            {{ $brandName }}
                        </h2>
                        @if($brandClaim)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $brandClaim }}</p>
                        @endif
                    </div>
                </div>

                {{-- Right: Action --}}
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

            {{-- Details grid --}}
            <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                @if($domain)
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Domain</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $domain }}</dd>
                    </div>
                @endif
                @if($companyName)
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Firma</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $companyName }}</dd>
                    </div>
                @endif
                @if($contactEmail)
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">E-Mail</dt>
                        <dd class="mt-1 truncate text-sm text-gray-950 dark:text-white">{{ $contactEmail }}</dd>
                    </div>
                @endif
                @if($city)
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Standort</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $city }}</dd>
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
