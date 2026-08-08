import { Mark, mergeAttributes } from '../tiptap-core.js'

/**
 * Keeps <button> elements alive in the editor — the consent banner's
 * `<button type="button" class="consent-control--open">` and friends, typed
 * into the HTML source view. Without it TipTap drops the element on parse and
 * only the label survives.
 *
 * A mark (like html-span), so the button sits inside its paragraph and its
 * label stays editable. `contenteditable` would otherwise swallow clicks on a
 * real button, so the node view is left to the browser and the button is made
 * inert with `pointer-events: none` in the editor stylesheet.
 *
 * The PHP counterpart (parse + render on the server) is
 * Mmoollllee\Cms\Tiptap\Marks\HtmlButton.
 */
export default Mark.create({
    name: 'htmlButton',
    priority: 200,

    // TipTap's default attribute handling already reads the DOM attribute on
    // parse and renders it back unless it is empty, so only the defaults need
    // spelling out — `type` falls back to `button` so an editorial button can
    // never submit a surrounding form.
    addAttributes() {
        return {
            class: { default: null },
            type: { default: 'button' },
        }
    },

    parseHTML() {
        return [
            {
                tag: 'button',
            },
        ]
    },

    renderHTML({ HTMLAttributes }) {
        return ['button', mergeAttributes(HTMLAttributes), 0]
    },
})
