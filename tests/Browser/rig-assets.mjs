// Fills <rig>/public/vendor/ from this package's node_modules and build output —
// Pusher and Echo for the Blade pages, jQuery, Vue and a React ESM bundle for the
// adapter pages, and links to beam.js and dist/. Nothing here is committed.
//
//   node tests/Browser/rig-assets.mjs            # rig at ../beam-rig, or $BEAM_RIG_DIR
import { build } from 'esbuild';
import { copyFileSync, mkdirSync, rmSync, symlinkSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const pkg = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const rig = resolve(process.env.BEAM_RIG_DIR ?? join(pkg, '..', 'beam-rig'));
const out = join(rig, 'public', 'vendor');
const mod = (p) => join(pkg, 'node_modules', p);

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

for (const [name, from] of Object.entries({
    'pusher.js': mod('pusher-js/dist/web/pusher.min.js'),
    'echo.js': mod('laravel-echo/dist/echo.iife.js'),
    'jquery.js': mod('jquery/dist/jquery.min.js'),
    'vue.esm-browser.js': mod('vue/dist/vue.esm-browser.prod.js'),
})) copyFileSync(from, join(out, name));

// Symlinks, so a rebuild of the client is picked up without rerunning this.
symlinkSync(relative(out, join(pkg, 'resources', 'dist', 'beam.js')), join(out, 'beam.js'));
symlinkSync(relative(out, join(pkg, 'dist')), join(out, 'dist'));

// React 19 ships no browser ESM build; the rig's React page imports this one.
await build({
    entryPoints: [join(pkg, 'tests', 'Browser', 'rig', 'resources', 'js', 'react-runtime.js')],
    bundle: true, format: 'esm', define: { 'process.env.NODE_ENV': '"production"' },
    outfile: join(out, 'react-esm.js'), logLevel: 'error',
});

console.log(`${relative(process.cwd(), out)} ready`);
