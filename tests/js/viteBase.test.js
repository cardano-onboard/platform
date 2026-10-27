import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Guards the asset-loading fix. laravel-vite-plugin bakes `base = ASSET_URL + "/build/"`
// at build time, and that base drives runtime dynamic-import chunk URLs. A host that
// serves assets from a per-deploy asset path settles ASSET_URL after CI has built the
// assets, so an absolute "/build/" base makes Inertia SPA navigation request page chunks
// from the app origin and 404 (the campaign details page symptom). The relative base in
// vite.config.js makes chunks resolve via import.meta.url, relative to the origin app.js
// was really loaded from. When the base is absolute, Vite emits string-concatenated chunk
// URLs and drops import.meta.url, so its presence in the entry is the discriminator.
//
// Asserts the actual build output. The Tests job builds nothing, so this file is run in
// the Dusk job straight after its build. When no build is present, a bare local `npm test`
// skips the check, but under CI (CI is set) a missing build fails it, so the guard cannot
// quietly stop running.
describe('vite build asset base', () => {
    const manifestPath = resolve(__dirname, '../../public/build/manifest.json');

    const run = existsSync(manifestPath) || import.meta.env.CI ? it : it.skip;

    run('resolves dynamic-import chunks relatively (import.meta.url), not from an absolute base', () => {
        expect(existsSync(manifestPath), 'no build found at public/build/manifest.json').toBe(true);
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        const entry = manifest['resources/js/app.js'];
        expect(entry, 'app.js entry missing from manifest').toBeTruthy();

        const entryJs = readFileSync(resolve(__dirname, '../../public/build', entry.file), 'utf8');
        expect(
            entryJs.includes('import.meta.url'),
            'entry bundle must use import.meta.url for chunk resolution (relative base); '
                + 'an absolute /build/ base would 404 SPA chunks on a separate asset host',
        ).toBe(true);
    });
});
