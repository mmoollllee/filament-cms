{{--
    Brand-agnostic standalone page shell (every routable, non-onepager page).
    Fallback only: an app view at resources/views/frontend/standalone.blade.php
    takes precedence. Publish to customize:
    `php artisan vendor:publish --tag=cms-frontend`.
--}}
<x-site.layout :content="$content" :initial-breadcrumbs="$initialBreadcrumbs">
    <main class="relative min-h-screen p-edge">
        @include('partials.floating-header', ['isOnepager' => false])

        <div class="relative z-10 pt-[calc(var(--site-edge-space,1rem)+4.5rem)] grid gap-8">
            @if ($backButton)
                <div class="shell mb-2">
                    <x-site.button :href="$backButton['href']" class="btn-surface btn-sm w-fit">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4" aria-hidden="true"><path d="M19 12H5" /><path d="m12 19-7-7 7-7" /></svg>
                        {{ $backButton['label'] }}
                    </x-site.button>
                </div>
            @endif

            @include($contentView, [
                'content' => $content,
                'navigationContext' => $navigationContext,
            ])
        </div>
    </main>

    <x-site.footer :legal-links="$legalLinks" />
</x-site.layout>
