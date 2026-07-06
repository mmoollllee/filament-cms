import * as esbuild from 'esbuild'
import { readdirSync } from 'node:fs'

const shared = {
    define: { 'process.env.NODE_ENV': `'production'` },
    bundle: true,
    mainFields: ['module', 'main'],
    platform: 'neutral',
    sourcemap: false,
    sourcesContent: false,
    treeShaking: true,
    target: ['es2020'],
    minify: true,
}

// Every .js file in resources/js/tiptap-extensions/ is an extension entry —
// drop a new file in and re-run `npm run build`; register the dist file as a
// Filament asset in CmsServiceProvider (see the TipTap HowTo in the demo).
const entries = readdirSync('./resources/js/tiptap-extensions').filter((file) => file.endsWith('.js'))

for (const entry of entries) {
    const context = await esbuild.context({
        ...shared,
        entryPoints: [`./resources/js/tiptap-extensions/${entry}`],
        outfile: `./resources/dist/tiptap-extensions/${entry}`,
    })
    await context.rebuild()
    await context.dispose()
    console.log(`built ${entry}`)
}

console.log('TipTap extensions built.')
