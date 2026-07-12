import {
    activeLocalSectionForWindowScroll,
    anchorIdFromHash,
    debounce,
    HISTORY_SETTLE_MS,
    historyHashForLocalSection,
    indicatorLabel as resolveIndicatorLabel,
    isUnmodifiedPrimaryClick,
    localSectionByHash,
    localSectionTargets,
    navigationCurrentPath as resolveNavigationCurrentPath,
    navigationHomePath as resolveNavigationHomePath,
    navigationRootPath as resolveNavigationRootPath,
    rafThrottle,
    scrollWindowTo,
    shouldShowBreadcrumbs,
} from './navigation-shared';
import { headerBarBehavior } from './header-bar';

export default (rootElement, initialNavigationContext = {}, options = {}) => ({
    ...headerBarBehavior(),
    rootElement,
    navigationContext: initialNavigationContext ?? {},
    options,
    activeLocalSection: null,
    menuOpen: false,
    init() {
        this.scrollFrame = rafThrottle(() => this.syncActiveLocalSection());
        this.settleHistory = debounce(() => {
            const hash = historyHashForLocalSection(this.activeLocalSection);

            window.history.replaceState(
                { path: this.currentNavigationPath(), hash },
                '',
                `${this.currentNavigationPath()}${hash}`,
            );
        }, HISTORY_SETTLE_MS);

        this.syncActiveLocalSectionFromHash(window.location.hash, false);
        this.syncActiveLocalSection(false);

        this._anchorClickHandler = (event) => this.handleAnchorClick(event);
        document.addEventListener('click', this._anchorClickHandler, true);
    },
    destroy() {
        if (this._anchorClickHandler) {
            document.removeEventListener('click', this._anchorClickHandler, true);
        }
    },
    currentNavigationContext() {
        return this.navigationContext ?? {};
    },
    showBreadcrumbs() {
        return shouldShowBreadcrumbs(
            this.currentNavigationContext(),
            this.options.showStandaloneBreadcrumbs ?? true,
        );
    },
    currentBreadcrumbItems() {
        const breadcrumbs = this.navigationContext?.breadcrumbs ?? [];
        const ancestors = breadcrumbs.filter((bc) => !bc.isCurrent);

        if (this.activeLocalSection) {
            const currentPage = breadcrumbs.find((bc) => bc.isCurrent);

            if (currentPage) {
                ancestors.push({ label: currentPage.label, path: currentPage.path });
            }
        }

        return ancestors;
    },
    currentIndicatorLabel() {
        return resolveIndicatorLabel(this.currentNavigationContext(), this.activeLocalSection);
    },
    currentNavigationRootPath() {
        return resolveNavigationRootPath(this.currentNavigationContext());
    },
    currentNavigationPath() {
        return resolveNavigationCurrentPath(this.currentNavigationContext(), window.location.pathname || '/');
    },
    homePath() {
        return resolveNavigationHomePath(this.currentNavigationContext());
    },
    toggleMenu() {
        this.menuOpen = !this.menuOpen;
    },
    closeMenu() {
        this.menuOpen = false;
    },
    onScroll() {
        this.scrollFrame();
    },
    handlePopstate() {
        this.syncActiveLocalSectionFromHash(window.location.hash, false);
        this.syncActiveLocalSection(false);
    },
    localSectionTargets() {
        // The article DOM is static after load — resolve the targets once and
        // drop the cache only when the elements left the document (morph/swap).
        if (this._targets && this._targets.some((target) => !target.element.isConnected)) {
            this._targets = null;
        }

        return this._targets ??= localSectionTargets(
            document.querySelector('article[data-navigation]'),
            this.currentNavigationContext().localSections ?? [],
        );
    },
    syncActiveLocalSection(replaceHistory = true) {
        const targets = this.localSectionTargets();

        if (targets.length === 0) {
            this.activeLocalSection = null;

            return;
        }

        const activeTarget = activeLocalSectionForWindowScroll(targets);

        this.setActiveLocalSection(activeTarget, replaceHistory);
    },
    syncActiveLocalSectionFromHash(hash, replaceHistory = false) {
        const targets = this.localSectionTargets();
        const localSection = localSectionByHash(targets, hash);

        if (!localSection) {
            return false;
        }

        this.setActiveLocalSection(localSection, replaceHistory);

        return true;
    },
    handleAnchorClick(event) {
        const link = event.target.closest('a[href]');

        if (!link || event.defaultPrevented || !isUnmodifiedPrimaryClick(event)) {
            return;
        }

        const url = new URL(link.href, window.location.origin);

        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || !url.hash) {
            return;
        }

        const target = document.getElementById(anchorIdFromHash(url.hash));

        if (!target) {
            return;
        }

        event.preventDefault();
        scrollWindowTo(target, 'smooth');
        window.history.pushState(null, '', url.hash);
        this.syncActiveLocalSectionFromHash(url.hash, false);
    },
    setActiveLocalSection(localSection, replaceHistory = true) {
        if (localSection?.id === this.activeLocalSection?.id) {
            return;
        }

        this.activeLocalSection = localSection ?? null;

        if (!replaceHistory) {
            return;
        }

        // Defer the history update until scrolling settles (HISTORY_SETTLE_MS).
        this.settleHistory();
    },
});
