import {
    anchorIdFromHash,
    debounce,
    HISTORY_SETTLE_MS,
    indicatorLabel as resolveIndicatorLabel,
    isUnmodifiedPrimaryClick,
    navigationCurrentPath as resolveNavigationCurrentPath,
    navigationHomePath as resolveNavigationHomePath,
    navigationRootPath as resolveNavigationRootPath,
    normalizedHash,
    parseJsonDataset,
    rafThrottle,
    scrollWindowTo,
    selectorEscape,
} from './navigation-shared';

/**
 * Alpine component behind the onepager shell (`frontend/onepager.blade.php`).
 *
 * Drives the section-based single page: lazy-loads section HTML from the
 * `content.fragment` endpoint, tracks the active section on scroll (updating
 * URL, document title and header indicator), handles in-page link clicks,
 * hash anchors and history navigation.
 *
 * View contract (see the shell Blade view):
 * - root element carries `data-content-endpoint`
 * - each section is `.onepager-section` with `data-path`, `data-loaded`,
 *   `data-title`, `data-label`, `data-navigation` and optional `data-anchor`
 *
 * Visual behavior (scroll hints, hero fades, header measuring, …) is NOT
 * part of this core: apps layer it on top via `registerCmsFrontend(Alpine,
 * overrides)` (see index.js) using the extension hooks `updateViewportState()`,
 * `showLogo()` and `onResize()` — the muench-tiefbau.de repo
 * (`resources/js/site/`) is the reference implementation.
 */
export default (rootElement) => ({
    rootElement,
    sections: [],
    sectionMap: new Map(),
    sectionNavigationContexts: new Map(),
    sectionAnchorMap: new Map(),
    sectionAnchorPathMap: new Map(),
    loadingPaths: new Set(),
    activePath: '/',
    menuOpen: false,
    lastSectionTop: null,
    popstateHandled: false,
    init() {
        this.scrollFrame = rafThrottle(() => this.handleScrollFrame());
        this.resizeFrame = rafThrottle(() => this.handleResizeFrame());
        this.settleHistory = debounce(() => this.replaceHistory(this.activePath), HISTORY_SETTLE_MS);

        this.sections = Array.from(this.rootElement.querySelectorAll('.onepager-section'));
        this.sectionMap = new Map(this.sections.map((section) => [section.dataset.path, section]));
        this.sectionNavigationContexts = new Map(this.sections.map((section) => [
            section.dataset.path,
            parseJsonDataset(section.dataset.navigation, null),
        ]));
        this.sectionAnchorMap = new Map(this.sections
            .filter((section) => Boolean(section.dataset.anchor))
            .map((section) => [section.dataset.path, section.dataset.anchor]));
        this.sectionAnchorPathMap = new Map(this.sections
            .filter((section) => Boolean(section.dataset.anchor))
            .map((section) => [section.dataset.anchor, section.dataset.path]));
        this.contentEndpoint = this.rootElement.dataset.contentEndpoint;
        this.activePath = this.pathForLocation(window.location.pathname || '/', window.location.hash)
            || window.location.pathname
            || '/';
        this.updateViewportState();

        this.loadingObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                void this.loadSection(entry.target.dataset.path);
            });
        }, {
            rootMargin: '320px 0px',
            threshold: 0.01,
        });

        this.sections.forEach((section) => this.loadingObserver.observe(section));
        this.prefetchAdjacent(this.activePath);
        this.updateDocumentTitle(this.titleForPath(this.activePath));

        window.requestAnimationFrame(async () => {
            const aliasedSectionPath = this.sectionPathFromHash(window.location.hash);

            if (aliasedSectionPath !== null) {
                await this.goToSection(aliasedSectionPath, false, 'auto', true);
                this.trackSectionTop();

                return;
            }

            await this.goToSection(this.activePath, false, 'auto', true);
            await this.handleHashNavigation(window.location.hash, this.activePath, 'auto');
            this.trackSectionTop();
        });
    },
    sectionNavigationContext(path) {
        return this.sectionNavigationContexts.get(path) ?? null;
    },
    currentNavigationContext() {
        return this.sectionNavigationContext(this.activePath);
    },
    showBreadcrumbs() {
        return this.activePath !== '/';
    },
    currentBreadcrumbItems() {
        return [];
    },
    currentSectionLabel() {
        const section = this.sectionMap.get(this.activePath);

        return section?.dataset.label || '';
    },
    currentIndicatorLabel() {
        return resolveIndicatorLabel(
            this.currentNavigationContext(),
            null,
            this.currentSectionLabel(),
        );
    },
    currentNavigationRootPath() {
        return resolveNavigationRootPath(this.currentNavigationContext(), this.activePath || '/');
    },
    currentNavigationPath() {
        return resolveNavigationCurrentPath(this.currentNavigationContext(), this.activePath || '/');
    },
    homePath() {
        return resolveNavigationHomePath(this.currentNavigationContext());
    },
    /** Extension hook: default header logo always visible. Apps override this
     *  for content-driven fades (e.g. Münch's `.hero-logo` visibility). */
    showLogo() {
        return true;
    },
    toggleMenu() {
        this.menuOpen = !this.menuOpen;
    },
    closeMenu() {
        this.menuOpen = false;
    },
    pathForLocation(pathname, hash = '') {
        if (pathname === '/') {
            return this.sectionPathFromHash(hash) || pathname;
        }

        return pathname;
    },
    sectionPathFromHash(hash) {
        const anchorId = anchorIdFromHash(hash);

        if (!anchorId) {
            return null;
        }

        return this.sectionAnchorPathMap.get(anchorId) ?? null;
    },
    sectionAnchorForPath(path) {
        return this.sectionAnchorMap.get(path) ?? null;
    },
    hrefForPath(path) {
        const anchor = this.sectionAnchorMap.get(path);

        return anchor ? `/#${anchor}` : path;
    },
    handlesSectionPath(path) {
        return this.sectionMap.has(path) && this.hrefForPath(path) === path;
    },
    titleForPath(path) {
        const section = this.sectionMap.get(path);

        return section?.dataset.title || document.title;
    },
    updateDocumentTitle(title) {
        if (!title) {
            return;
        }

        document.title = title;
    },
    prefetchAdjacent(path) {
        const index = this.sections.findIndex((s) => s.dataset.path === path);

        if (index === -1) {
            return;
        }

        [this.sections[index - 1], this.sections[index + 1]]
            .filter(Boolean)
            .forEach((section) => {
                void this.loadSection(section.dataset.path);
            });
    },
    async loadSection(path) {
        const section = this.sectionMap.get(path);

        if (!section || section.dataset.loaded === 'true' || this.loadingPaths.has(path)) {
            return;
        }

        this.loadingPaths.add(path);
        section.dataset.loading = 'true';

        try {
            const url = `${this.contentEndpoint}?path=${encodeURIComponent(path)}&presentation=section`;
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`Failed to load section ${path}`);
            }

            section.innerHTML = await response.text();

            // Initialize Alpine (and Livewire components) inside the freshly injected HTML.
            if (window.Alpine) {
                window.Alpine.initTree(section);
            }

            const layoutPreset = response.headers.get('X-Fragment-Layout-Preset');
            if (layoutPreset) {
                layoutPreset.split(/\s+/).filter(Boolean).forEach(cls => section.classList.add(cls));
            }

            const navigation = parseJsonDataset(response.headers.get('X-Fragment-Navigation'), null);

            if (navigation) {
                section.dataset.navigation = JSON.stringify(navigation);
                this.sectionNavigationContexts.set(path, navigation);
            }

            section.dataset.loaded = 'true';

            // Extension hook for freshly injected section HTML (e.g. re-binding
            // third-party link handlers that only scan on page load).
            section.dispatchEvent(new CustomEvent('cms:section-loaded', {
                bubbles: true,
                detail: { path },
            }));

            this.updateViewportState();
        } catch (error) {
            console.error(error);
        } finally {
            this.loadingPaths.delete(path);
            delete section.dataset.loading;
        }
    },
    async goToSection(path, pushState = false, behavior = 'smooth', forceScroll = false) {
        const section = this.sectionMap.get(path);

        if (!section) {
            return;
        }

        await this.loadSection(path);

        if (forceScroll || path !== this.activePath) {
            section.scrollIntoView({
                behavior,
                block: 'start',
            });
        }

        this.activePath = path;
        this.prefetchAdjacent(this.activePath);
        this.updateDocumentTitle(this.titleForPath(this.activePath));

        if (pushState) {
            this.pushHistory(this.activePath);
        }

        window.requestAnimationFrame(() => {
            this.updateViewportState();
        });
    },
    async navigateToSection(path, pushState = true, behavior = 'smooth', hash = '') {
        this.closeMenu();

        if (path !== this.activePath) {
            await this.goToSection(path, false, hash ? 'auto' : behavior);
        }

        const sectionAnchor = this.sectionAnchorForPath(path);
        const normalizedTargetHash = normalizedHash(hash);
        const isSectionAliasHash = sectionAnchor !== null && normalizedTargetHash === `#${sectionAnchor}`;

        await this.handleHashNavigation(isSectionAliasHash ? '' : hash, path, behavior);

        if (pushState) {
            this.pushHistory(path, hash);
        }
    },
    shouldHandleLink(link, event) {
        if (!link || event.defaultPrevented) {
            return false;
        }

        if (link.hasAttribute('download') || link.dataset.noLazy !== undefined) {
            return false;
        }

        if (link.target && link.target !== '_self') {
            return false;
        }

        return isUnmodifiedPrimaryClick(event);
    },
    async handleLinkClick(event) {
        const link = event.target.closest('a[href]');

        // Bare "#" links (e.g. the spam-protected contact links) are handled by
        // their own scripts — never treat them as navigation, and suppress the
        // default jump to the document top.
        if (link?.getAttribute('href') === '#') {
            event.preventDefault();

            return;
        }

        if (!this.shouldHandleLink(link, event)) {
            return;
        }

        const url = new URL(link.href, window.location.origin);

        if (url.origin !== window.location.origin) {
            return;
        }

        event.preventDefault();

        const handled = await this.tryLazyNavigate(url);

        if (!handled) {
            window.location.assign(url.href);
        }
    },
    async tryLazyNavigate(url) {
        const hash = url.hash || '';
        const aliasedSectionPath = this.sectionPathFromHash(hash);

        if (
            aliasedSectionPath !== null
            && (url.pathname === '/' || url.pathname === window.location.pathname)
        ) {
            await this.navigateToSection(aliasedSectionPath, true, 'smooth', hash);

            return true;
        }

        if (this.handlesSectionPath(url.pathname)) {
            await this.navigateToSection(url.pathname, true, 'smooth', hash);

            return true;
        }

        return false;
    },
    trackSectionTop() {
        const section = this.sectionMap.get(this.activePath);

        if (section) {
            this.lastSectionTop = section.getBoundingClientRect().top;
        }
    },
    /** Extension hook: core resize handling is the rAF'd drift correction only.
     *  Overrides that cache viewport geometry reset their caches here, then
     *  MUST call `this.resizeFrame()` to keep the drift correction alive. */
    onResize() {
        this.resizeFrame();
    },
    handleResizeFrame() {
        const section = this.sectionMap.get(this.activePath);

        if (section && this.lastSectionTop !== null) {
            const drift = section.getBoundingClientRect().top - this.lastSectionTop;

            if (Math.abs(drift) > 1) {
                window.scrollBy(0, drift);
            }

            this.lastSectionTop = section.getBoundingClientRect().top;
        }

        this.updateViewportState();
    },
    onScroll() {
        this.scrollFrame();
    },
    handleScrollFrame() {
        this.determineActivePath();
        this.updateViewportState();
        this.trackSectionTop();
    },
    /**
     * Extension hook: recompute everything derived from the current viewport
     * position. The core computes nothing here — it only guarantees the call
     * sites: init(), after loadSection() injection, in the goToSection() rAF,
     * every scroll frame and every resize frame. Apps layer their visual
     * behavior (scroll hints, hero fades, …) on top via override factories.
     */
    updateViewportState() {},
    adjacentSection(direction) {
        const index = this.sections.findIndex((section) => section.dataset.path === this.activePath);

        if (index === -1) {
            return null;
        }

        return this.sections[direction === 'up' ? index - 1 : index + 1] ?? null;
    },
    determineActivePath() {
        const viewportCenter = window.innerHeight / 2;
        let nextActivePath = this.activePath;
        let bestDistance = Number.POSITIVE_INFINITY;

        this.sections.forEach((section) => {
            const rect = section.getBoundingClientRect();
            const sectionCenter = rect.top + (rect.height / 2);
            const distance = Math.abs(sectionCenter - viewportCenter);

            if (distance < bestDistance) {
                bestDistance = distance;
                nextActivePath = section.dataset.path || nextActivePath;
            }
        });

        if (nextActivePath !== this.activePath) {
            this.activePath = nextActivePath;
            this.prefetchAdjacent(this.activePath);
            this.updateDocumentTitle(this.titleForPath(this.activePath));

            // Defer the history update until scrolling settles (HISTORY_SETTLE_MS).
            this.settleHistory();
        }
    },
    async handlePopstate() {
        // History traversal between hash URLs fires popstate AND hashchange —
        // handle the burst once (the timeout clears after both listeners ran).
        if (this.popstateHandled) {
            return;
        }

        this.popstateHandled = true;
        setTimeout(() => {
            this.popstateHandled = false;
        }, 0);

        this.closeMenu();

        const path = window.location.pathname || '/';
        const hash = window.location.hash || '';
        const aliasedSectionPath = this.sectionPathFromHash(hash);

        if (path === '/' && aliasedSectionPath !== null) {
            await this.goToSection(aliasedSectionPath, false, 'auto', true);

            return;
        }

        if (!this.handlesSectionPath(path)) {
            return;
        }

        await this.goToSection(path, false, 'auto', true);
        await this.handleHashNavigation(hash, path, 'auto');
    },
    pushHistory(path, hash = '') {
        window.history.pushState({ path, hash }, '', this.historyUrl(path, hash));
    },
    replaceHistory(path, hash = '') {
        window.history.replaceState({ path, hash }, '', this.historyUrl(path, hash));
    },
    historyUrl(path, hash = '') {
        const sectionAnchor = this.sectionAnchorForPath(path);
        const currentHash = normalizedHash(hash);

        if (sectionAnchor !== null && (!currentHash || currentHash === `#${sectionAnchor}`)) {
            return this.hrefForPath(path);
        }

        return `${path}${currentHash}`;
    },
    sectionTargetElement(path, hash) {
        const anchorId = anchorIdFromHash(hash);
        const section = this.sectionMap.get(path);

        if (!anchorId || !section) {
            return null;
        }

        return section.querySelector(`#${selectorEscape(anchorId)}`);
    },
    async handleHashNavigation(hash, path, behavior = 'smooth') {
        const currentHash = normalizedHash(hash);

        if (!currentHash || !this.sectionMap.has(path)) {
            return false;
        }

        await this.loadSection(path);

        const target = this.sectionTargetElement(path, currentHash);

        if (!target) {
            return false;
        }

        scrollWindowTo(target, behavior);

        return true;
    },
});
