import { Extension } from '@tiptap/core'

/**
 * Declares the LinkPicker's extra attributes (title, wire:navigate) on the
 * built-in `link` mark via TipTap's global attributes — WITHOUT replacing the
 * mark itself (a second mark named `link` would conflict with the bundled one).
 *
 * Without this, the editor drops both attributes as soon as a marked text is
 * re-edited: setLink() only keeps attributes the mark declares. The PHP
 * counterpart (rendering + parsing on the server) is
 * Mmoollllee\Cms\Tiptap\Marks\LinkPicker.
 */
export default Extension.create({
    name: 'linkAttributes',

    addGlobalAttributes() {
        return [
            {
                types: ['link'],
                attributes: {
                    title: {
                        default: null,
                        parseHTML: (element) => element.getAttribute('title') || null,
                        renderHTML: (attributes) => {
                            if (!attributes.title) return {}
                            return { title: attributes.title }
                        },
                    },
                    'wire:navigate': {
                        default: null,
                        parseHTML: (element) => (element.hasAttribute('wire:navigate') ? true : null),
                        renderHTML: (attributes) => {
                            if (!attributes['wire:navigate']) return {}
                            return { 'wire:navigate': '' }
                        },
                    },
                },
            },
        ]
    },
})
