import { Mark, mergeAttributes } from '@tiptap/core'

export default Mark.create({
    name: 'htmlSpan',
    priority: 200,

    // TipTap's default attribute handling already reads the DOM attribute on
    // parse and renders it back unless it is empty.
    addAttributes() {
        return {
            class: { default: null },
        }
    },

    parseHTML() {
        return [
            {
                tag: 'span',
            },
        ]
    },

    renderHTML({ HTMLAttributes }) {
        return ['span', mergeAttributes(HTMLAttributes), 0]
    },
})
