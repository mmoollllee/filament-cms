import { clamp01, rafThrottle } from './navigation-shared';

// Module-scoped so a second registerCmsFrontend() call (a fresh store object)
// still can't stack window listeners.
let bound = false;

/**
 * Alpine store factory for window scroll progress.
 *
 * The floating header (`partials/floating-header.blade.php`) binds against it:
 * `progress` drives the progress bar behind the breadcrumb/indicator area,
 * `depthMeters` the little "-x,x m" depth label following its edge.
 */
export default () => ({
    progress: 0,
    depthMeters: '0,0',
    init() {
        if (bound) {
            return;
        }

        bound = true;

        const compute = () => {
            const scrollTop = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;

            // clamp01 also floors rubber-band overscroll (negative scrollY on iOS).
            this.progress = docHeight > 0 ? clamp01(scrollTop / docHeight) * 100 : 0;
            this.depthMeters = Math.max(0, scrollTop * 0.01).toFixed(1).replace('.', ',');
        };
        const update = rafThrottle(compute);

        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update, { passive: true });
        compute();
    },
});
