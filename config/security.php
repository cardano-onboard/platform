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

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Addresses whose X-Forwarded-For, X-Forwarded-Proto, X-Forwarded-Host and
    | X-Forwarded-Port headers are believed; from anything else connecting directly they
    | are ignored, so a client cannot choose the address the per-IP limits count. A trusted
    | proxy may append to X-Forwarded-For, and must set or replace X-Forwarded-Proto and
    | set or strip X-Forwarded-Host and X-Forwarded-Port.
    |
    | Empty trusts loopback and private ranges only, which means any client that can reach
    | the app from one of those addresses. '*' on its own trusts the connecting address,
    | whatever it is, and is only safe when no client can reach the app except through that
    | one proxy. Otherwise a comma separated list of IPs or CIDR ranges with a prefix of 1
    | or more. Entries that are not valid are ignored, and a value with no valid entry
    | trusts nothing. Read by App\Http\Middleware\TrustProxies.
    |
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
];
