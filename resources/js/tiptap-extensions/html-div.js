import { Node, mergeAttributes } from '@tiptap/core'

const reservedDivClasses = [
    'lead',
    'grid-layout',
    'grid-column',
    'ProseMirror-focused',
]

export default Node.create({
    name: 'htmlDiv',
    group: 'block',
    content: 'block*',
    priority: 200,
    defining: true,

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
                tag: 'div',
                getAttrs: (element) => {
                    const cls = element.getAttribute('class')
                    if (!cls) return false

                    const classes = cls.split(' ')
                    if (classes.some((c) => reservedDivClasses.includes(c)))
                        return false

                    if (element.getAttribute('data-type') === 'customBlock')
                        return false

                    return null
                },
            },
        ]
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes), 0]
    },
})
