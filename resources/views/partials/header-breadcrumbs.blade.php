{{--
    Breadcrumb trail (home icon + ancestor links) in the floating header.
    No viewport breakpoints: headerBar (header-bar.js) measures what fits —
    ancestors drop root-first, the home icon only when even it alone has no
    room (navHidden). The width/padding/opacity transition carries the
    logo-hover evade, which collapses the nav via inline styles.
--}}
<nav
    class="relative z-10 flex items-center h-12 min-w-0 gap-2 py-2 pl-4 text-sm text-white overflow-hidden whitespace-nowrap transition-[width,padding,opacity] duration-300"
    data-role="header-breadcrumbs"
    x-cloak
    x-show="showBreadcrumbs() && !menuOpen && !headerBar.navHidden"
>
    <a class="inline-flex items-center justify-center no-underline shrink-0" x-bind:href="homePath()" aria-label="Zurück zum Start">
        @svg('home', 'size-5')
    </a>

    <template x-for="(item, index) in currentBreadcrumbItems()" :key="item.path">
        <a
            x-bind:href="item.path"
            class="max-w-44 shrink-0 truncate font-bold no-underline"
            x-show="index >= headerBar.hiddenAncestors"
            x-text="item.label"
        ></a>
    </template>
</nav>
