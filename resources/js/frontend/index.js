/**
 * filament-cms frontend runtime — the JS half of the package's frontend views.
 *
 * The package Blade views (onepager shell, floating header) bind against the
 * Alpine components registered here. Consuming apps import this module from
 * the vendor dir and wire it inside their own `alpine:init` listener — the
 * same pattern as the consent-control runtime:
 *
 *     // resources/js/app.js
 *     import { registerCmsFrontend } from '../../vendor/mmoollllee/filament-cms/resources/js/frontend';
 *
 *     document.addEventListener('alpine:init', () => {
 *         registerCmsFrontend(window.Alpine);
 *     });
 *
 * The components ship ARCHITECTURE only (section lazy-loading, history,
 * navigation context, menu state). Brand behavior lives in the app: pass
 * override factories to layer mixins over a component (or replace single
 * methods) without forking the file — the extension hooks are
 * `updateViewportState()`, `showLogo()` and `onResize()`:
 *
 *     registerCmsFrontend(window.Alpine, {
 *         onepager: (el) => ({
 *             ...scrollHintsMixin(),
 *             updateViewportState() { this.updateScrollHints(); },
 *         }),
 *         childNavigation: () => ({ ...headerBarMixin() }),
 *     });
 *
 * Compose multiple mixins into ONE override object and define collision-prone
 * hooks (updateViewportState/onResize) exactly once — the merge is a flat
 * member set. Reference implementation: muench-tiefbau.de `resources/js/site/`.
 */
import siteOnepager from './site-onepager';
import siteChildNavigation from './site-child-navigation';

export { default as siteOnepager } from './site-onepager';
export { default as siteChildNavigation } from './site-child-navigation';
export * from './navigation-shared';

/**
 * Merge an override fragment over a package component. Descriptor-based so
 * accessors (get/set) survive — a plain spread would freeze getters to their
 * value at merge time.
 */
const mergeComponent = (component, override) => (
    override ? Object.defineProperties(component, Object.getOwnPropertyDescriptors(override)) : component
);

/**
 * Register the Alpine components the package frontend views expect.
 *
 * @param {object} Alpine the started Alpine instance (window.Alpine)
 * @param {{ onepager?: Function, childNavigation?: Function }} overrides
 *        optional per-component factories whose members are merged on top of
 *        the package component (receive the same arguments)
 */
export function registerCmsFrontend(Alpine, overrides = {}) {
    Alpine.data('siteOnepager', (el) => mergeComponent(
        siteOnepager(el),
        overrides.onepager?.(el),
    ));

    Alpine.data('siteChildNavigation', (el, initialNavigationContext = {}, options = {}) => mergeComponent(
        siteChildNavigation(el, initialNavigationContext, options),
        overrides.childNavigation?.(el, initialNavigationContext, options),
    ));
}
