import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { nodePolyfills } from 'vite-plugin-node-polyfills';
import path from 'path';

export default defineConfig(({ command }) => ({
    // Relative base for production builds so runtime dynamic-import chunks resolve
    // via import.meta.url — i.e. relative to wherever app.js was loaded from. On
    // Vapor, assets are served from a per-deploy CloudFront path (ASSET_URL) that is
    // unknown at build time (CI builds the assets), so baking an absolute /build/ base
    // makes Inertia SPA navigation request chunks from the app origin → 404. A relative
    // base sidesteps that: chunks load from the same origin/path as app.js (CloudFront
    // on Vapor, same-origin on the self-hosted image). Laravel's @vite entry tags are
    // unaffected — the manifest keeps relative `file` paths and is prefixed with the
    // runtime ASSET_URL. Dev keeps the plugin default so HMR works.
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
