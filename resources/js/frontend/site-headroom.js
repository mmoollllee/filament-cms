/**
 * Headroom behaviour for a sticky site header (Alpine component `siteHeadroom`,
 * registered by registerCmsFrontend()). No package view binds it — an app
 * header opts in.
 *
 * The header slides out while the visitor scrolls down and comes back on the
 * first small scroll up, so the menu is in reach mid-page without scrolling
 * back to the top. The component only tracks `headerHidden`; the template turns
 * it into a hiding class and wires the events, e.g.:
 *
 *     <header
 *         class="sticky top-0 transition-transform focus-within:translate-y-0 focus-within:transition-none"
 *         x-data="siteHeadroom"
 *         x-bind:class="{ '-translate-y-full': headerHidden }"
 *         x-on:scroll.window.passive="onWindowScroll()"
 *         x-on:focusin="onFocusIn()"
 *     >
 *
 * Keep the header visible while it holds focus (`focus-within` above): a link
 * the keyboard reaches must never sit off-screen.
 */

/** Downward travel before the header hides — rides out scroll jitter. */
const HIDE_AFTER = 12;

/** Upward travel that brings the header back — deliberately small. */
const SHOW_AFTER = 4;

export default () => {
    // Where the current scroll direction began: the lowest position while the
    // header shows, the highest while it is hidden.
    let turningPoint = 0;
    // The position of the last scroll event (see onFocusIn).
    let lastScrollY = 0;

    return {
        headerHidden: false,

        init() {
            turningPoint = this.scrollPosition();
            lastScrollY = window.scrollY;
        },

        /** window.scrollY clamped to the scrollable range: iOS reports rubber-band overscroll outside it. */
        scrollPosition() {
            const maxScrollY = Math.max(document.documentElement.scrollHeight - window.innerHeight, 0);

            return Math.min(Math.max(window.scrollY, 0), maxScrollY);
        },

        /**
         * Keyboard focus into the slid-out header: the browser scrolls the page
         * to reach the focused link, in Chrome before focusin even fires. Go back
         * to where the last scroll event left the page; focus-within (template)
         * shows the header at the top anyway and keeps it there while it holds
         * focus.
         */
        onFocusIn() {
            if (! this.headerHidden) {
                return;
            }

            const scrollY = lastScrollY;

            this.headerHidden = false;
            window.requestAnimationFrame(() => window.scrollTo({ top: scrollY, behavior: 'instant' }));
        },

        onWindowScroll() {
            lastScrollY = window.scrollY;

            const scrollY = this.scrollPosition();

            // Within its own height of the top the header stays: that is where
            // the page layout puts it anyway.
            if (scrollY <= this.$root.offsetHeight) {
                this.headerHidden = false;
                turningPoint = scrollY;

                return;
            }

            if (this.headerHidden) {
                turningPoint = Math.max(turningPoint, scrollY);

                if (turningPoint - scrollY > SHOW_AFTER) {
                    this.headerHidden = false;
                    turningPoint = scrollY;
                }

                return;
            }

            turningPoint = Math.min(turningPoint, scrollY);

            if (scrollY - turningPoint > HIDE_AFTER) {
                this.headerHidden = true;
                turningPoint = scrollY;
            }
        },
    };
};
