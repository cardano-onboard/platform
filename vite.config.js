import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { nodePolyfills } from 'vite-plugin-node-polyfills';
import path from 'path';

export default defineConfig(({ command }) => ({
    // Relative base for production builds so runtime dynamic-import chunks resolve
    // via import.meta.url, i.e. relative to wherever app.js was loaded from. A host
    // that serves assets from a per-deploy asset path (ASSET_URL) settles that path
    // after CI has built the assets, so baking an absolute /build/ base makes Inertia
    // SPA navigation request chunks from the app origin and 404. A relative base
    // sidesteps that: chunks load from the same origin and path as app.js, whether
    // that is a separate asset host or the app's own origin. Laravel's @vite entry
    // tags are unaffected, because the manifest keeps relative `file` paths and is
    // prefixed with the runtime ASSET_URL. Dev keeps the plugin default so HMR works.
    base: command === 'build' ? './' : undefined,
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // @meshsdk/core-cst imports node:crypto (pbkdf2Sync). Polyfill it for the
        // browser bundle, otherwise the production build fails at rollup time.
        nodePolyfills({
            include: ['crypto', 'buffer', 'stream', 'events', 'util'],
            globals: { Buffer: true, global: true, process: true },
        }),
    ],
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['tests/js/setup.js'],
        // Collect only the suite, not everything in the tree that happens to be
        // named like a test. Without this the default glob walks the whole project,
        // so a git worktree checked out inside the repository is collected too and
        // the reported test count silently counts the same files more than once.
        include: ['tests/js/**/*.{test,spec}.js'],
        server: {
            deps: {
                inline: ['vuetify'],
            },
        },
        css: true,
        alias: {
            '@meshsdk/core': path.resolve(__dirname, 'tests/js/__mocks__/meshsdk-core.js'),
            '@/plugins/vue-cardano.js': path.resolve(__dirname, 'tests/js/__mocks__/vue-cardano.js'),
        },
    },
}));
