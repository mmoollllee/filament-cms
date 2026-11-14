/**
 * Livewire must not morph an element whose Alpine component is still loading.
 *
 * Filament 5.11 loads the Alpine component of a builder (as of a rich editor, a select)
 * asynchronously: async-alpine leaves the element alone until the script has loaded, then
 * initialises it. A Livewire update that arrives in between — the content edit page sends
 * one right after it loads, the resource lock's init event carrying the rich editors' first
 * state — morphs the page, and Alpine's morph initialises every element it patches in clone
 * mode. An element still waiting has no state to clone, so its x-data is evaluated without
 * the component: "builderFormComponent is not defined", then "item is not defined" for every
 * block inside it. Nothing breaks, the element initialises once its component has loaded,
 * but the console fills with errors on every page load.
 *
 * Such an element is left as it was rendered: async-alpine initialises it, and the next
 * update morphs it like any other. Registered once, whether Livewire has started already
 * or not, and not again when wire:navigate runs the page's scripts anew.
 */
;(() => {
    if (window.filamentCmsAsyncMorphGuard) {
        return
    }

    window.filamentCmsAsyncMorphGuard = true

    const guard = () =>
        window.Livewire.hook('morph.updating', ({ el, skip }) => {
            if (el._x_async === 'init' || el._x_async === 'await') {
                skip()
            }
        })

    if (window.Livewire) {
        guard()
    } else {
        document.addEventListener('livewire:init', guard, { once: true })
    }
})()
