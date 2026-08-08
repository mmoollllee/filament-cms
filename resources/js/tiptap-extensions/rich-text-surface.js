import { Extension } from '../tiptap-core.js'

/**
 * Marks the editing surface as rich-text content.
 *
 * An app styles its content through `.richtext` — the class the frontend and
 * the block previews wrap rendered content in. The editor should answer to the
 * same hook, or typography stops at the preview and the editor shows the
 * panel's defaults instead of the site's.
 *
 * The class goes on the ProseMirror element (`editor.view.dom`), NOT on
 * Filament's `fi-fo-rich-editor-main`: that container also holds the merge-tag
 * and custom-block side panels and is laid out as a flex row/column by Filament,
 * so an app rule like `.richtext { display: grid }` would rearrange the editor
 * UI itself. `view.dom` contains nothing but the content.
 */
export default Extension.create({
    name: 'richTextSurface',

    onCreate() {
        this.editor.view.dom.classList.add('richtext')
    },
})
