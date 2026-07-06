<nav
    class="relative z-10 items-center hidden h-12 min-w-0 gap-2 py-2 pl-4 text-sm text-white sm:flex"
    data-role="header-breadcrumbs"
    x-cloak
    x-show="showBreadcrumbs() && !menuOpen"
>
    <a class="inline-flex items-center justify-center no-underline shrink-0" x-bind:href="homePath()" aria-label="Zurück zum Start">
        @svg('home', 'size-5')
    </a>

    <template x-for="item in currentBreadcrumbItems()" :key="item.path">
        <a x-bind:href="item.path" class="max-w-[11rem] truncate font-bold no-underline" x-text="item.label"></a>
    </template>
</nav>
