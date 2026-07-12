export const HEADER_OFFSET = 112;

export const clamp01 = (value) => Math.min(1, Math.max(0, value));

/** Plain primary-button click without modifier keys (new-tab/window gestures stay native). */
export const isUnmodifiedPrimaryClick = (event) => (
    event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey
);

/** Delay before scroll-synced history updates settle — avoids address-bar
 *  animations in browsers like Ecosia (iOS WKWebView). */
export const HISTORY_SETTLE_MS = 500;

/** Trailing debounce: runs the callback once, `delay` after the last call. */
export const debounce = (callback, delay) => {
    let timer = null;

    return () => {
        clearTimeout(timer);
        timer = setTimeout(callback, delay);
    };
};

/** rAF-throttled wrapper: coalesces event bursts (scroll/resize) into one frame callback. */
export const rafThrottle = (callback) => {
    let ticking = false;

    return () => {
        if (ticking) {
            return;
        }

        ticking = true;

        window.requestAnimationFrame(() => {
            callback();
            ticking = false;
        });
    };
};

export const parseJsonDataset = (value, fallback) => {
    if (!value) {
        return fallback;
    }

    try {
        return JSON.parse(value);
    } catch (error) {
        console.error(error);

        return fallback;
    }
};

export const selectorEscape = (value) => {
    if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(value);
    }

    return value.replace(/["\\.#:[\]]/g, '\\$&');
};

export const normalizedHash = (hash) => {
    if (!hash) {
        return '';
    }

    return hash.startsWith('#') ? hash : `#${hash}`;
};

export const anchorIdFromHash = (hash) => {
    const currentHash = normalizedHash(hash);

    if (!currentHash) {
        return '';
    }

    const raw = currentHash.slice(1);

    try {
        return decodeURIComponent(raw);
    } catch {
        // Malformed percent-encoding (legacy non-UTF-8 links): fall back to
        // the raw fragment instead of aborting the caller mid-init.
        return raw;
    }
};

/**
 * Floating-header bar geometry (px). Mirrors the Blade utility classes in
 * `partials/floating-header.blade.php` + `partials/header-breadcrumbs.blade.php`
 * — keep both in sync when styling changes.
 */
export const HEADER_BAR = {
    indicatorMax: 224, // widest the indicator ever gets
    indicatorMin: 96, // below this it marquees instead of shrinking further
    itemMax: 176, // breadcrumb link max-w-44
    itemGap: 8, // nav gap-2
    navPadding: 16, // nav pl-4
    homeWidth: 20, // home icon size-5
    indicatorMarginLeft: 12, // indicator ml-3
    indicatorMarginRight: 8, // indicator mr-2
    buttonWidth: 48, // menu button (h-12 aspect-square)
    logoClearance: 16, // resting air between logo and bar
    evadeGap: 48, // hover: start evading while the logo edge is still this far away
};

/**
 * Distribute the header-bar space left of the menu button between the
 * breadcrumb nav (home icon + ancestor links) and the indicator — measured
 * widths, no viewport breakpoints. Ancestors drop root-first (the trail
 * stays contiguous up to the current page), the home icon only goes when
 * even it alone no longer fits; the indicator yields down to
 * `indicatorMin` before any breadcrumb is dropped and marquees once it is
 * narrower than its label.
 *
 * @param {{ available: number, indicatorNatural: number, ancestorWidths: number[], breadcrumbsVisible: boolean }} input
 *        `available` = header width minus resting logo and clearance;
 *        `ancestorWidths` = natural label widths in breadcrumb order.
 * @returns {{ navHidden: boolean, hiddenAncestors: number, navWidth: number, indicatorWidth: number }}
 */
export const fitHeaderBar = ({ available, indicatorNatural, ancestorWidths, breadcrumbsVisible }) => {
    const chrome = HEADER_BAR.indicatorMarginLeft + HEADER_BAR.indicatorMarginRight + HEADER_BAR.buttonWidth;
    const indicatorCap = Math.min(indicatorNatural, HEADER_BAR.indicatorMax);
    const withoutNav = () => ({
        navHidden: true,
        hiddenAncestors: ancestorWidths.length,
        navWidth: 0,
        indicatorWidth: Math.max(0, Math.min(indicatorCap, available - chrome)),
    });

    if (!breadcrumbsVisible) {
        return withoutNav();
    }

    const indicatorMin = Math.min(indicatorNatural, HEADER_BAR.indicatorMin);
    const navBudget = available - chrome - indicatorMin;
    let navWidth = HEADER_BAR.navPadding + HEADER_BAR.homeWidth;

    if (navBudget < navWidth) {
        return withoutNav();
    }

    let visibleAncestors = 0;

    for (let i = ancestorWidths.length - 1; i >= 0; i -= 1) {
        const cost = HEADER_BAR.itemGap + Math.min(ancestorWidths[i], HEADER_BAR.itemMax);

        if (navWidth + cost > navBudget) {
            break;
        }

        navWidth += cost;
        visibleAncestors += 1;
    }

    return {
        navHidden: false,
        hiddenAncestors: ancestorWidths.length - visibleAncestors,
        navWidth,
        indicatorWidth: Math.min(indicatorCap, available - chrome - navWidth),
    };
};

const breadcrumbMode = (navigationContext = null) => {
    return navigationContext?.breadcrumbMode || 'none';
};

const currentBreadcrumbs = (navigationContext = null) => {
    return navigationContext?.breadcrumbs ?? [];
};

export const shouldShowBreadcrumbs = (navigationContext = null, showStandaloneBreadcrumbs = true) => {
    const breadcrumbs = currentBreadcrumbs(navigationContext);

    if (breadcrumbs.length === 0) {
        return false;
    }

    switch (breadcrumbMode(navigationContext)) {
        case 'child':
            return true;
        case 'standalone':
            return showStandaloneBreadcrumbs;
        default:
            return false;
    }
};

export const indicatorLabel = (navigationContext = null, activeLocalSection = null, fallback = '') => {
    return activeLocalSection?.label
        || navigationContext?.indicatorLabel
        || fallback;
};

export const navigationRootPath = (navigationContext = null, fallback = '/') => {
    return navigationContext?.rootPath || fallback;
};

export const navigationCurrentPath = (navigationContext = null, fallback = '/') => {
    return navigationContext?.currentPath || fallback;
};

export const navigationHomePath = (navigationContext = null, fallback = '/') => {
    return navigationContext?.homePath || fallback;
};

export const historyHashForLocalSection = (localSection = null) => {
    return localSection ? normalizedHash(localSection.href) : '';
};

export const localSectionTargets = (container, sections = []) => {
    if (!container || sections.length === 0) {
        return [];
    }

    return sections
        .map((section) => {
            const element = container.querySelector(`#${selectorEscape(section.id)}`);

            return element ? { ...section, element } : null;
        })
        .filter(Boolean);
};

export const localSectionByHash = (targets, hash) => {
    const anchorId = anchorIdFromHash(hash);

    if (!anchorId) {
        return null;
    }

    return targets.find((target) => target.id === anchorId) ?? null;
};

export const scrollWindowTo = (target, behavior = 'smooth') => {
    const top = window.scrollY + target.getBoundingClientRect().top - HEADER_OFFSET;

    window.scrollTo({
        top: Math.max(top, 0),
        behavior,
    });
};

export const activeLocalSectionForWindowScroll = (targets, offset = HEADER_OFFSET + 20) => {
    const threshold = offset;
    let activeTarget = null;

    targets.forEach((target) => {
        if (target.element.getBoundingClientRect().top <= threshold) {
            activeTarget = target;
        }
    });

    return activeTarget;
};
