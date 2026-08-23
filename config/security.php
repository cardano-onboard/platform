<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Response Security Headers
    |--------------------------------------------------------------------------
    |
    | Applied by the App\Http\Middleware\SecurityHeaders middleware to dynamic
    | (Laravel-served) responses. Static assets are served directly by nginx, so
    | their headers live in docker/nginx/default.conf. Each value is
    | env-overridable; an empty string disables that header.
    |
    */

    // Content Security Policy, as directive => sources. Permissive enough for the Vite
    // bundle, Vuetify's injected inline styles, bunny.net fonts, and MeshJS WASM, while
    // still adding real clickjacking/injection protections (frame-ancestors, object-src,
    // base-uri, form-action). The SecurityHeaders middleware assembles these into the
    // header, and in local dev appends the Vite dev-server origins so the SAME policy is
    // exercised locally (only extended, never disabled). Tighten per deployment.
    'csp_directives' => [
        'default-src' => "'self'",
        'script-src' => "'self' 'unsafe-inline' 'unsafe-eval' 'wasm-unsafe-eval'",
        'style-src' => "'self' 'unsafe-inline' https://fonts.bunny.net",
        'font-src' => "'self' https://fonts.bunny.net data:",
        'img-src' => "'self' data: https:",
        'connect-src' => "'self' https: wss:",
        'frame-ancestors' => "'none'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
    ],

    // Optional full-string override; when set it is used verbatim (no local dev extension).
    'csp_override' => env('CONTENT_SECURITY_POLICY', ''),

    'coop' => env('CROSS_ORIGIN_OPENER_POLICY', 'same-origin'),

    'corp' => env('CROSS_ORIGIN_RESOURCE_POLICY', 'same-origin'),

    // COEP is opt-in: `require-corp` can break cross-origin assets (e.g. CDN
    // fonts) that do not send their own CORP header, so it defaults to off
    // pending per-deployment verification.
    'coep' => env('CROSS_ORIGIN_EMBEDDER_POLICY', ''),

    'permissions_policy' => env('PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()'),
];
