import {
    anchorIdFromHash,
    clamp01,
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
 * Scroll-hint fade geometry (px): a hint's opacity ramps linearly with the free
 * gap between its button zone and the nearest section content — from 0 while
 * the content still overlaps the zone by `overlap`, to 1 once the zone has
 * `free` clearance. Hints therefore glimmer in shortly before the content edge
 * clears their zone instead of popping at a fixed threshold.
 */
const SCROLL_HINT_FADE = { overlap: 40, free: 64 };

/** Below this opacity a hint ignores pointer/keyboard input — a barely visible
 *  pill must not catch accidental clicks (e.g. users with low vision). */
const SCROLL_HINT_INTERACTIVE_MIN = 0.3;

/**
 * Sticky-ride dissolve (viewport-height fractions): once the middle of the
 * empty band between two section contents passes a hint's resting spot, the
 * pill anchors to that middle and travels with the page — fading out between
 * `range` and `dead` distance from the viewport center, where the next
 * section boundary takes over (so re-anchoring never pops visibly).
 */
const SCROLL_HINT_CENTER_FADE = { dead: 0.04, range: 0.14 };

/**
 * Redundancy fade (px): once the content of the section a hint points to
 * intrudes into the viewport by more than `start`, the hint starts fading and
 * is gone `range` later — a pointer to something already on screen is noise.
 */
const SCROLL_HINT_TARGET_FADE = { start: 16, range: 144 };

/**
 * Alpine component behind the onepager shell (`frontend/onepager.blade.php`).
 *
 * Drives the section-based single page: lazy-loads section HTML from the
 * `content.fragment` endpoint, tracks the active section on scroll (updating
 * URL, document title and header indicator), handles in-page link clicks,
 * hash anchors, history navigation — and the scroll hints (up/down pills
 * whose opacity follows the scroll position).
 *
 * View contract (see the shell Blade view):
 * - root element carries `data-content-endpoint`
 * - each section is `.onepager-section` with `data-path`, `data-loaded`,
 *   `data-title`, `data-label`, `data-navigation` and optional `data-anchor`
 * - an optional `.hero-logo` inside the "/" section hides the header logo
 *   while it is visible (`showLogo()`)
 *
 * Apps register it via `registerCmsFrontend(Alpine)` (see index.js) and may
 * override single methods by spreading their own on top.
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
    heroLogoVisible: false,
    scrollHints: {
        up: { opacity: 0, shift: 0, held: false },
        down: { opacity: 0, shift: 0, held: false },
    },
    scrollHintRestingZones: { up: null, down: null },
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
    showLogo() {
        return this.activePath !== '/' || !this.heroLogoVisible;
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

            if (path === '/') {
                this.heroLogo = undefined;
            }

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
    onResize() {
        // Resting pill positions move with the viewport — remeasure next pass.
        this.scrollHintRestingZones = { up: null, down: null };

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
    /** Recompute everything derived from the current viewport position. */
    updateViewportState() {
        this.updateHeroLogoVisibility();
        this.updateScrollHints();
    },
    heroLogoElement() {
        // Cached: queried per scroll frame otherwise; loadSection('/') resets it.
        if (this.heroLogo === undefined) {
            this.heroLogo = this.rootElement.querySelector('[data-path="/"] .hero-logo');
        }

        return this.heroLogo;
    },
    updateHeroLogoVisibility() {
        const heroLogo = this.heroLogoElement();

        if (!heroLogo) {
            this.heroLogoVisible = false;

            return;
        }

        const rect = heroLogo.getBoundingClientRect();

        this.heroLogoVisible = rect.bottom > 0 && rect.top < window.innerHeight;
    },
    adjacentSection(direction) {
        const index = this.sections.findIndex((section) => section.dataset.path === this.activePath);

        if (index === -1) {
            return null;
        }

        return this.sections[direction === 'up' ? index - 1 : index + 1] ?? null;
    },
    /**
     * Vertical extent of a section's actual content — the union of its child
     * rects. The section wrapper itself is >= 100vh with centered content, so
     * wrapper visibility says nothing about whether anything is on screen.
     */
    sectionContentRect(section) {
        if (!section) {
            return null;
        }

        let top = Number.POSITIVE_INFINITY;
        let bottom = Number.NEGATIVE_INFINITY;

        Array.from(section.children).forEach((child) => {
            const rect = child.getBoundingClientRect();

            if (rect.height === 0 && rect.width === 0) {
                return;
            }

            top = Math.min(top, rect.top);
            bottom = Math.max(bottom, rect.bottom);
        });

        if (top === Number.POSITIVE_INFINITY) {
            return null;
        }

        return { top, bottom };
    },
    /**
     * The pill's resting geometry from CSS — measured while unshifted, cached
     * while riding (a mid-ride measurement would include the applied translate).
     * After a mid-ride cache reset (resize) the recorded shift is backed out
     * once; this requires the pill's translate to never be CSS-transitioned.
     */
    scrollHintRestingZone(direction) {
        const cached = this.scrollHintRestingZones[direction];

        if (cached) {
            return cached;
        }

        const shift = this.scrollHints[direction].shift;

        const button = direction === 'up' ? this.$refs.scrollHintUp : this.$refs.scrollHintDown;
        const rect = button?.getBoundingClientRect();

        // Zero rect = not rendered (display:none below the lg breakpoint / x-cloak).
        if (!rect || (rect.height === 0 && rect.width === 0)) {
            return null;
        }

        const zone = {
            center: (rect.top + rect.bottom) / 2 - shift,
            halfHeight: rect.height / 2,
        };

        this.scrollHintRestingZones[direction] = zone;

        return zone;
    },
    /** Per-frame geometry shared by both hint computations (avoids re-measuring). */
    scrollHintContext() {
        const current = this.sectionMap.get(this.activePath) ?? null;
        const neighbor = { up: this.adjacentSection('up'), down: this.adjacentSection('down') };
        const wrapperRect = (section) => section?.getBoundingClientRect() ?? null;

        return {
            current,
            neighbor,
            wrapperRect: {
                current: wrapperRect(current),
                up: wrapperRect(neighbor.up),
                down: wrapperRect(neighbor.down),
            },
            contentRect: {
                current: this.sectionContentRect(current),
                up: this.sectionContentRect(neighbor.up),
                down: this.sectionContentRect(neighbor.down),
            },
            viewportCenter: window.innerHeight / 2,
        };
    },
    /**
     * Signed clearance between a hint zone and the nearest section content:
     * positive = free space, negative = overlap depth.
     */
    scrollHintFreeGap(zone, contentRects) {
        let gap = null;

        contentRects.forEach((rect) => {
            if (!rect) {
                return;
            }

            const sectionGap = Math.max(zone.top - rect.bottom, rect.top - zone.bottom);

            gap = gap === null ? sectionGap : Math.min(gap, sectionGap);
        });

        return gap;
    },
    /**
     * The handover point between the active section and the neighbor — where
     * determineActivePath() flips (viewport center crossing the midpoint of
     * the two wrapper centers). The dissolve anchors here so a riding pill is
     * guaranteed invisible when it re-anchors to the next boundary, even for
     * sections with very unequal content heights.
     */
    scrollHintFlipPoint(direction, context) {
        const currentRect = context.wrapperRect.current;
        const neighborRect = context.wrapperRect[direction];

        if (!currentRect || !neighborRect) {
            return null;
        }

        return (currentRect.top + currentRect.bottom + neighborRect.top + neighborRect.bottom) / 4;
    },
    /**
     * Scroll hints: pills pointing to the previous/next section. Their opacity
     * follows the scroll position (SCROLL_HINT_FADE ramp against the nearest
     * content), and they ride sticky on the middle of the empty band between
     * two section contents: resting at their fixed spot while the band middle
     * is beyond it, anchored to the moving middle afterwards, dissolving
     * toward the section handover point (SCROLL_HINT_CENTER_FADE) — and fading
     * out once the target's own content shows in the viewport
     * (SCROLL_HINT_TARGET_FADE). Hover/focus holds interactivity and forces
     * full visibility via CSS; below SCROLL_HINT_INTERACTIVE_MIN the pill is
     * disabled entirely.
     */
    updateScrollHints() {
        const restingZones = {
            up: this.scrollHintRestingZone('up'),
            down: this.scrollHintRestingZone('down'),
        };
        // No measurable pill (display:none below lg) → skip the geometry pass.
        const context = restingZones.up || restingZones.down ? this.scrollHintContext() : null;

        ['up', 'down'].forEach((direction) => {
            const next = context
                ? this.scrollHintStateFor(direction, context, restingZones[direction])
                : { opacity: 0, shift: 0 };
            const hint = this.scrollHints[direction];

            // Leaf writes only: identical values don't re-trigger bindings.
            hint.opacity = next.opacity;
            hint.shift = next.shift;
        });
    },
    scrollHintStateFor(direction, context, resting) {
        const inert = { opacity: 0, shift: 0 };

        if (!resting || !context.current || context.current.dataset.loaded !== 'true' || !context.neighbor[direction]) {
            return inert;
        }

        const activeRect = context.contentRect.current;
        const targetRect = context.contentRect[direction];
        const bandMiddle = activeRect && targetRect
            ? (direction === 'down'
                ? (activeRect.bottom + targetRect.top) / 2
                : (targetRect.bottom + activeRect.top) / 2)
            : null;

        // Sticky ride: pull the pill from its resting spot onto the band middle
        // once that middle passes it, but never beyond the viewport center.
        let shift = 0;

        if (bandMiddle !== null) {
            shift = direction === 'down'
                ? Math.min(0, Math.max(bandMiddle, context.viewportCenter) - resting.center)
                : Math.max(0, Math.min(bandMiddle, context.viewportCenter) - resting.center);
        }

        const center = resting.center + shift;
        const zone = { top: center - resting.halfHeight, bottom: center + resting.halfHeight };
        const gap = this.scrollHintFreeGap(zone, [context.contentRect.up, activeRect, context.contentRect.down]);
        const ramp = gap === null
            ? 1
            : clamp01((gap + SCROLL_HINT_FADE.overlap) / (SCROLL_HINT_FADE.overlap + SCROLL_HINT_FADE.free));

        const flipPoint = this.scrollHintFlipPoint(direction, context);
        const centerFactor = flipPoint === null
            ? 1
            : clamp01(
                (Math.abs(flipPoint - context.viewportCenter) - SCROLL_HINT_CENTER_FADE.dead * window.innerHeight)
                    / (SCROLL_HINT_CENTER_FADE.range * window.innerHeight),
            );

        // Redundancy fade: once the target section's content is already in the
        // viewport, the pointer to it dims and disappears shortly after.
        let targetFactor = 1;

        if (targetRect) {
            const intrusion = direction === 'down'
                ? window.innerHeight - targetRect.top
                : targetRect.bottom;

            targetFactor = 1 - clamp01((intrusion - SCROLL_HINT_TARGET_FADE.start) / SCROLL_HINT_TARGET_FADE.range);
        }

        return {
            // Two decimals / whole px: stable values avoid needless style writes.
            opacity: Math.round(ramp * centerFactor * targetFactor * 100) / 100,
            shift: Math.round(shift),
        };
    },
    scrollHintShift(direction) {
        return this.scrollHints[direction].shift;
    },
    scrollHintOpacity(direction) {
        if (this.menuOpen) {
            return 0;
        }

        return this.scrollHints[direction].opacity;
    },
    /** Hover/focus holds interactivity so a pill can't go inert under the cursor. */
    holdScrollHint(direction, held) {
        this.scrollHints[direction].held = held;
    },
    scrollHintInteractive(direction) {
        if (this.menuOpen) {
            return false;
        }

        return this.scrollHints[direction].held
            || this.scrollHints[direction].opacity >= SCROLL_HINT_INTERACTIVE_MIN;
    },
    /** Title of the section the hint scrolls to — doubles as the button label. */
    scrollHintTargetLabel(direction) {
        return this.adjacentSection(direction)?.dataset.label ?? '';
    },
    async goToAdjacentSection(direction) {
        const target = this.adjacentSection(direction);

        if (target) {
            await this.navigateToSection(target.dataset.path);
        }
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
