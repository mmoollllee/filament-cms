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
