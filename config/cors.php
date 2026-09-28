<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // 'v1/*' is the short claim route on the claim subdomain (routes/claim.php). It is
    // the same claim endpoint as 'api/claim/v1/*', registered without the /api prefix so
    // the URL, and therefore the QR payload, stays short. Without it a webview wallet's
    // OPTIONS preflight gets the router's bare 200 with no Access-Control-* headers and
    // the claim POST is never sent. Keep both entries: one endpoint, two addresses, one
    // policy.
    'paths' => ['api/*', 'v1/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
