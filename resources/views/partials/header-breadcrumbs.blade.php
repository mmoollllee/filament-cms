{{--
    Brand-agnostic breadcrumb fallback (an app view at
    resources/views/partials/header-breadcrumbs.blade.php takes precedence):
    home icon + ancestor links with plain CSS truncation, hidden below md.
    Measured space fitting + logo-hover evade are app territory — see the
    muench-tiefbau.de reference implementation.
--}}
<nav
    class="relative z-10 items-center hidden h-12 min-w-0 gap-2 py-2 pl-4 text-sm text-white md:flex"
    data-role="header-breadcrumbs"
    x-cloak
    x-show="showBreadcrumbs() && !menuOpen"
>
    <a class="inline-flex items-center justify-center no-underline shrink-0" x-bind:href="homePath()" aria-label="{{ __('cms::frontend.back_to_start') }}">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7h-6v7H4a1 1 0 0 1-1-1z" /></svg>
    </a>

    <template x-for="item in currentBreadcrumbItems()" :key="item.path">
        <a x-bind:href="item.path" class="max-w-44 truncate font-bold no-underline" x-text="item.label"></a>
    </template>
</nav>
