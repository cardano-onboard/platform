import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { nodePolyfills } from 'vite-plugin-node-polyfills';
import path from 'path';

export default defineConfig({
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
});
