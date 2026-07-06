@props(['legalLinks' => [], 'tenant' => null])

@php
    // Anonymous components don't inherit the caller's locals, so resolve the tenant
    // ourselves (explicit prop → request-scoped singleton) instead of assuming it.
    $tenant ??= app(\Mmoollllee\Cms\Support\Tenancy\CurrentTenant::class)->get();
    $footerTagline = \Mmoollllee\Cms\Cms::footerTagline();
@endphp

<footer class="relative z-10 mt-20 bg-black/[0.3] pt-12 pb-16 text-center">
    <div class="shell">
        @if (! empty($legalLinks))
            <nav class="mb-10 flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm">
                @foreach ($legalLinks as $item)
                    <a
                        href="{{ $item['href'] }}"
                        class="text-white/35 no-underline transition-colors hover:text-white/60"
                    >{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endif

        @if (filled($footerTagline))
            <p class="mb-3 text-lg font-black uppercase tracking-[0.25em] text-white/90 sm:text-xl">
                {{ $footerTagline }}
            </p>
        @endif

        <p class="mb-0 text-xs tracking-wider text-white/60">
            &copy; {{ date('Y') }} {{ $tenant->displayName() }}
        </p>
    </div>
</footer>
