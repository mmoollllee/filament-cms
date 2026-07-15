import { Extension, getMarkRange } from '@tiptap/core'

/**
 * WordPress-style link bubble: when the caret/selection lands inside an
 * existing link, a small floating tooltip shows the href plus "Bearbeiten"
 * and "Entfernen" actions.
 *
 * "Bearbeiten" reuses the LinkPicker toolbar button (marked with
 * [data-cms-tool="link-picker"] by LinkPickerPlugin::getEditorTools()) so the
 * whole mount flow — current editorSelection, prefilled attributes, edit-mode
 * modal — stays in one place. "Entfernen" unsets the mark client-side.
 *
 * Styles: resources/css/builder.css (.cms-link-bubble-*).
 */
export default Extension.create({
    name: 'linkBubble',

    addStorage() {
        return {
            el: null,
            hrefEl: null,
            reposition: null,
        }
    },

    onSelectionUpdate() {
        update(this.editor, this.storage)
    },

    onTransaction() {
        update(this.editor, this.storage)
    },

    onFocus() {
        update(this.editor, this.storage)
    },

    onBlur() {
        // Interacting with the bubble keeps editor focus (mousedown is
        // prevented), so any real blur — clicking elsewhere, opening the
        // modal — should dismiss the bubble.
        hide(this.storage)
    },

    onDestroy() {
        if (this.storage.reposition) {
            window.removeEventListener('scroll', this.storage.reposition, true)
            window.removeEventListener('resize', this.storage.reposition)
        }

        this.storage.el?.remove()
        this.storage.el = null
    },
})

function update(editor, storage) {
    if (! editor.isEditable || ! editor.isFocused || ! editor.isActive('link')) {
        hide(storage)

        return
    }

    const el = ensureElement(editor, storage)
    const href = editor.getAttributes('link').href ?? ''

    storage.hrefEl.textContent = href
    storage.hrefEl.title = href

    position(editor, el)
}

function hide(storage) {
    if (storage.el) {
        storage.el.style.display = 'none'
    }
}

function position(editor, el) {
    const { state } = editor
    const range = getMarkRange(state.doc.resolve(state.selection.from), state.schema.marks.link)
    const coords = editor.view.coordsAtPos(range ? range.from : state.selection.from)

    el.style.display = 'flex'

    // Render once to measure, then clamp into the viewport.
    const width = el.offsetWidth || 0
    const left = Math.min(Math.max(coords.left, 8), window.innerWidth - width - 8)

    el.style.left = `${left}px`
    el.style.top = `${coords.bottom + 6}px`
}

function ensureElement(editor, storage) {
    if (storage.el) {
        return storage.el
    }

    const el = document.createElement('div')
    el.className = 'cms-link-bubble'
    el.style.display = 'none'

    // Keep the editor focused (and its selection intact) while clicking
    // around inside the bubble.
    el.addEventListener('mousedown', (event) => event.preventDefault())

    const href = document.createElement('span')
    href.className = 'cms-link-bubble-href'
    el.appendChild(href)

    const edit = button('Bearbeiten', () => {
        editor.options.element
            .closest('.fi-fo-rich-editor')
            ?.querySelector('[data-cms-tool="link-picker"]')
            ?.click()
    })

    const remove = button('Entfernen', () => {
        editor.chain().focus().extendMarkRange('link').unsetLink().run()
        hide(storage)
    })

    el.appendChild(edit)
    el.appendChild(remove)

    document.body.appendChild(el)

    storage.el = el
    storage.hrefEl = href

    storage.reposition = () => {
        if (el.style.display !== 'none') {
            update(editor, storage)
        }
    }

    // Panel content scrolls in nested containers — capture phase catches them all.
    window.addEventListener('scroll', storage.reposition, true)
    window.addEventListener('resize', storage.reposition)

    return el
}

function button(label, onClick) {
    const btn = document.createElement('button')
    btn.type = 'button'
    btn.className = 'cms-link-bubble-btn'
    btn.textContent = label
    btn.addEventListener('click', onClick)

    return btn
}
