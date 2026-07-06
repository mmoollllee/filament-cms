import { Mark, mergeAttributes } from '@tiptap/core'

export default Mark.create({
    name: 'htmlSpan',
    priority: 200,

    addAttributes() {
        return {
            class: {
                default: null,
                parseHTML: (element) => element.getAttribute('class') || null,
                renderHTML: (attributes) => {
                    if (!attributes.class) return {}
                    return { class: attributes.class }
                },
            },
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
