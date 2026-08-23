import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Guards the Vapor asset-loading fix. laravel-vite-plugin bakes `base = ASSET_URL +
// "/build/"` at build time, and that base drives runtime dynamic-import chunk URLs.
// On Vapor the per-deploy CloudFront ASSET_URL is unknown when CI builds the assets,
// so an absolute "/build/" base makes Inertia SPA navigation request page chunks from
// the app origin → 404 (the campaign details page symptom). The relative base in
// vite.config.js makes chunks resolve via import.meta.url — relative to app.js's real
// (CloudFront) origin. When the base is absolute, Vite emits string-concatenated chunk
// URLs and drops import.meta.url, so its presence in the entry is the discriminator.
//
// Asserts the actual build output. CI builds assets before running the JS suite; when
// no build is present (bare local `npm test`) the check is skipped rather than failing.
describe('vite build asset base', () => {
    const manifestPath = resolve(__dirname, '../../public/build/manifest.json');

    const run = existsSync(manifestPath) ? it : it.skip;

    run('resolves dynamic-import chunks relatively (import.meta.url), not from an absolute base', () => {
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        const entry = manifest['resources/js/app.js'];
        expect(entry, 'app.js entry missing from manifest').toBeTruthy();

        const entryJs = readFileSync(resolve(__dirname, '../../public/build', entry.file), 'utf8');
        expect(
            entryJs.includes('import.meta.url'),
            'entry bundle must use import.meta.url for chunk resolution (relative base); '
                + 'an absolute /build/ base would 404 SPA chunks on Vapor/CloudFront',
        ).toBe(true);
    });
});
