import { fitHeaderBar, HEADER_BAR, rafThrottle } from './navigation-shared';

/**
 * Floating-header bar behavior, mixed into both `siteOnepager` and
 * `siteChildNavigation` (spread before their own members). The bar's Blade
 * side lives in `partials/floating-header.blade.php` (which calls
 * `initHeaderBar()` once via `x-init`) and `partials/header-breadcrumbs.blade.php`
 * (which binds `headerBar.navHidden` / `headerBar.hiddenAncestors`).
 *
 * Fitting: breadcrumbs and indicator share the space left of the menu
 * button. `fitHeaderBar()` (navigation-shared.js) decides from measured
 * label widths — not viewport breakpoints — which ancestors fit, whether
 * even the home icon has to go, and how wide the indicator may grow; the
 * indicator marquees when it ends up narrower than its label. Re-fits on
 * resize, on font load and whenever indicator label or breadcrumb items
 * change.
 *
 * Evade: hovering the logo expands it (the width comes from app CSS, so it
 * is never assumed here). A rAF loop follows the expanding edge and slides
 * the breadcrumb nav — and, where the logo reaches further, the indicator —
 * out of its way (width + opacity collapse), restoring both on mouseleave.
 * Thresholds are cached at hover start so the receding bar cannot
 * re-trigger itself mid-animation; the menu button never moves.
 */
export const headerBarBehavior = () => ({
    headerBar: { navHidden: false, hiddenAncestors: 0 },
    initHeaderBar() {
        const header = this.rootElement.closest('.site-header') ?? this.rootElement.querySelector('.site-header');

        if (!header) {
            return;
        }

        this._headerEls = {
            header,
            logo: header.querySelector('.logo-link'),
            nav: header.querySelector('[data-role="header-breadcrumbs"]'),
            indicator: header.querySelector('[data-role="header-indicator"]'),
            button: header.querySelector('.nav-menu-btn'),
        };
        this._evadeTier = 0;
        this._refitFrame = rafThrottle(() => this.refitHeaderBar());

        window.addEventListener('resize', this._refitFrame);
        // Label widths shift once webfonts arrive.
        document.fonts?.ready.then(this._refitFrame);
        this.$watch(
            () => [
                this.currentIndicatorLabel(),
                this.showBreadcrumbs(),
                ...this.currentBreadcrumbItems().map((item) => item.label),
            ].join('|'),
            () => this._refitFrame(),
        );

        this._headerEls.logo?.addEventListener('mouseenter', () => this.startLogoEvade());
        this._headerEls.logo?.addEventListener('mouseleave', () => this.stopLogoEvade());

        // Measurer refs register after this ancestor x-init — defer the first pass.
        this.$nextTick(() => this.refitHeaderBar());
    },
    refitHeaderBar() {
        const els = this._headerEls;
        const indicatorMeasure = this.$refs.indicatorMeasure;
        const breadcrumbMeasure = this.$refs.breadcrumbMeasure;

        if (!els || !indicatorMeasure || !breadcrumbMeasure) {
            return;
        }

        indicatorMeasure.textContent = this.currentIndicatorLabel();
        const ancestorWidths = this.currentBreadcrumbItems().map((item) => {
            breadcrumbMeasure.textContent = item.label;

            return breadcrumbMeasure.scrollWidth;
        });

        // The layout budget builds on the resting logo — mid-hover widths are
        // the evade's business, not the fit's.
        if (!this._logoHovered || this._logoRestWidth === undefined) {
            this._logoRestWidth = els.logo?.offsetWidth ?? 0;
        }

        this._indicatorNatural = indicatorMeasure.scrollWidth;

        const fit = fitHeaderBar({
            available: els.header.clientWidth - this._logoRestWidth - HEADER_BAR.logoClearance,
            indicatorNatural: this._indicatorNatural,
            ancestorWidths,
            breadcrumbsVisible: this.showBreadcrumbs(),
        });

        this.headerBar.navHidden = fit.navHidden;
        this.headerBar.hiddenAncestors = fit.hiddenAncestors;
        this._navFitWidth = fit.navWidth;
        this._indicatorFitWidth = fit.indicatorWidth;

        if (this._evadeTier < 2) {
            this.applyIndicatorWidth(fit.indicatorWidth);
        }
    },
    applyIndicatorWidth(width) {
        const { indicator } = this._headerEls;

        if (!indicator) {
            return;
        }

        indicator.style.width = `${width}px`;

        if (this._indicatorNatural > width) {
            indicator.dataset.marquee = '';
            indicator.style.setProperty('--marquee-offset', `-${this._indicatorNatural - width + 12}px`);
        } else {
            delete indicator.dataset.marquee;
            indicator.style.removeProperty('--marquee-offset');
        }
    },
    startLogoEvade() {
        const els = this._headerEls;

        if (!els?.logo || this.menuOpen) {
            return;
        }

        this._logoHovered = true;

        const navRect = els.nav?.getBoundingClientRect();
        const navLeft = navRect && navRect.width > 0 ? navRect.left : Infinity;
        const buttonLeft = els.button?.getBoundingClientRect().left ?? Infinity;
        // Where the indicator's left edge sits once the nav is out of the way
        // (the bar is right-aligned, so the button never moves).
        const indicatorLeft = buttonLeft
            - HEADER_BAR.indicatorMarginRight
            - (els.indicator?.getBoundingClientRect().width ?? 0);

        const step = () => {
            if (!this._logoHovered) {
                return;
            }

            const logoRight = els.logo.getBoundingClientRect().right + HEADER_BAR.evadeGap;

            this.applyEvadeTier(logoRight > indicatorLeft ? 2 : (logoRight > navLeft ? 1 : 0));
            this._evadeFrame = window.requestAnimationFrame(step);
        };

        step();
    },
    stopLogoEvade() {
        this._logoHovered = false;
        window.cancelAnimationFrame(this._evadeFrame);
        this.applyEvadeTier(0);
    },
    applyEvadeTier(tier) {
        if (tier === this._evadeTier) {
            return;
        }

        const { nav, indicator } = this._headerEls;

        if (nav && !this.headerBar.navHidden) {
            if (tier >= 1 && this._evadeTier < 1) {
                window.clearTimeout(this._navRestoreTimer);
                // Freeze the current width so the collapse has a transition start value.
                this._navFrozenWidth = nav.offsetWidth;
                nav.style.width = `${this._navFrozenWidth}px`;
                void nav.offsetWidth;
                nav.style.width = '0px';
                nav.style.paddingLeft = '0px';
                nav.style.opacity = '0';
            } else if (tier < 1 && this._evadeTier >= 1) {
                nav.style.width = `${this._navFrozenWidth}px`;
                nav.style.paddingLeft = '';
                nav.style.opacity = '';
                window.clearTimeout(this._navRestoreTimer);
                // Hand the width back to the layout once the slide-in finished.
                this._navRestoreTimer = window.setTimeout(() => nav.style.removeProperty('width'), 350);
            }
        }

        if (tier >= 2 && this._evadeTier < 2) {
            if (indicator) {
                indicator.style.width = '0px';
                indicator.style.opacity = '0';
                // The margins would keep a stub of the bar over the logo.
                indicator.style.marginLeft = '0px';
                indicator.style.marginRight = '0px';
            }
        } else if (tier < 2 && this._evadeTier >= 2) {
            this.applyIndicatorWidth(this._indicatorFitWidth ?? 0);
            indicator?.style.removeProperty('opacity');
            indicator?.style.removeProperty('margin-left');
            indicator?.style.removeProperty('margin-right');
        }

        this._evadeTier = tier;
    },
});
