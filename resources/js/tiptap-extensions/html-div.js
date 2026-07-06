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
