import { defineConfig } from 'vite';
import dts from 'vite-plugin-dts';

/**
 * Two builds from one source tree.
 *
 *   vite build              → dist/            ESM, four entries, for npm (@realrashid/beam, /vue, /react, /jquery)
 *   vite build --mode iife  → resources/dist/  one IIFE exposing window.Beam, committed, served by @beamAssets
 *
 * The IIFE is built from iife.ts rather than index.ts on purpose: an entry with
 * only a default export makes window.Beam the class itself, not a namespace
 * object with a .default on it.
 */
export default defineConfig(({ mode }) => {
    if (mode === 'sw') {
        // The service worker: a plain script, no exports, served at {prefix}/sw.js.
        return {
            build: {
                outDir: 'resources/dist',
                emptyOutDir: false,
                lib: { entry: 'resources/js/sw.ts', name: 'BeamSW', formats: ['iife'], fileName: () => 'beam-sw.js' },
                rollupOptions: { external: [] },
                minify: 'esbuild',
                sourcemap: false,
            },
        };
    }

    if (mode === 'iife') {
        return {
            build: {
                outDir: 'resources/dist',
                emptyOutDir: false,
                lib: {
                    entry: 'resources/js/iife.ts',
                    name: 'Beam',
                    formats: ['iife'],
                    fileName: () => 'beam.js',
                },
                // Nothing is external, because nothing needs to be: the client
                // imports no dependency at all. Echo is the application's — the
                // IIFE reads window.Echo — which is why this bundle is 12 KB
                // gzipped rather than a hundred.
                rollupOptions: { external: [] },
                minify: 'esbuild',
                sourcemap: false,
            },
        };
    }

    return {
        plugins: [dts({ include: ['resources/js'], exclude: ['resources/js/sw.ts'], outDir: 'dist', rollupTypes: false })],
        build: {
            outDir: 'dist',
            emptyOutDir: true,
            lib: {
                entry: {
                    index: 'resources/js/index.ts',
                    vue: 'resources/js/vue.ts',
                    react: 'resources/js/react.ts',
                    jquery: 'resources/js/jquery.ts',
                },
                formats: ['es'],
            },
            rollupOptions: {
                external: ['laravel-echo', 'pusher-js', 'vue', 'react', 'jquery'],
            },
            minify: false,
            sourcemap: true,
        },
    };
});
