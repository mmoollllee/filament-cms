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
 * Project-specific behavior stays in the app: pass override factories to
 * extend/replace single methods of a component without forking the file:
 *
 *     registerCmsFrontend(window.Alpine, {
 *         onepager: (el) => ({
 *             showLogo() { return true; },   // e.g. never hide the header logo
 *         }),
 *     });
 */
import siteOnepager from './site-onepager';
import siteChildNavigation from './site-child-navigation';
import createScrollStore from './scroll-store';

export { default as siteOnepager } from './site-onepager';
export { default as siteChildNavigation } from './site-child-navigation';
export { default as createScrollStore } from './scroll-store';
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
 * Register the Alpine store + components the package frontend views expect.
 *
 * @param {object} Alpine the started Alpine instance (window.Alpine)
 * @param {{ onepager?: Function, childNavigation?: Function }} overrides
 *        optional per-component factories whose members are merged on top of
 *        the package component (receive the same arguments)
 */
export function registerCmsFrontend(Alpine, overrides = {}) {
    Alpine.store('scroll', createScrollStore());

    Alpine.data('siteOnepager', (el) => mergeComponent(
        siteOnepager(el),
        overrides.onepager?.(el),
    ));

    Alpine.data('siteChildNavigation', (el, initialNavigationContext = {}, options = {}) => mergeComponent(
        siteChildNavigation(el, initialNavigationContext, options),
        overrides.childNavigation?.(el, initialNavigationContext, options),
    ));
}
