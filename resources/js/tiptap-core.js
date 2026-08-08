/**
 * TipTap's runtime, borrowed from Filament instead of bundled.
 *
 * The extensions in tiptap-extensions/ used to import from `@tiptap/core`, which
 * esbuild inlined: a private copy of TipTap + ProseMirror in every entry, ~156 KB
 * each, six of them loaded on any panel page with an editor. Filament's rich-editor
 * component publishes the copy its own Editor runs on, so we take that one — the
 * built files drop to a few hundred bytes, and the extensions are created by the
 * exact runtime that instantiates them instead of a second copy that has to stay
 * compatible with it.
 *
 * Ordering is safe: the component assigns the global while its own module
 * evaluates, and only afterwards dynamically imports the files listed in
 * RichContentPlugin::getTipTapJsExtensions().
 *
 * This file lives one level above tiptap-extensions/ on purpose — every .js file
 * in that directory is a build entry point.
 */
const core = globalThis.FilamentRichEditor?.tiptap?.core

if (! core) {
    // Filament catches a failing extension import and only console.errors it, so
    // say what actually broke: without this the symptom is a silently missing
    // mark or class, which reads like a CSS or content problem.
    throw new Error(
        'window.FilamentRichEditor.tiptap.core is unavailable — Filament changed how it exposes TipTap to custom rich-editor extensions.',
    )
}

export const { Extension, Mark, Node, getMarkRange, mergeAttributes } = core
