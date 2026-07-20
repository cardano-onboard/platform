<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Configurable headers (config/security.php) — set only when non-empty.
        foreach ([
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
            'Cross-Origin-Opener-Policy' => config('security.coop'),
            'Cross-Origin-Resource-Policy' => config('security.corp'),
            'Cross-Origin-Embedder-Policy' => config('security.coep'),
            'Permissions-Policy' => config('security.permissions_policy'),
        ] as $header => $value) {
            if (! empty($value)) {
                $response->headers->set($header, $value);
            }
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Assemble the Content-Security-Policy header from the configured directives.
     *
     * In local dev the SAME policy is used, only extended with the Vite dev-server
     * origins (any localhost/127.0.0.1 port) and its ws:// HMR socket — so CSP is
     * genuinely exercised during development and a new disallowed resource still trips
     * it, matching staging/production behavior. A full-string override bypasses both.
     */
    private function contentSecurityPolicy(): ?string
    {
        if ($override = config('security.csp_override')) {
            return $override;
        }

        $directives = config('security.csp_directives');
        if (empty($directives)) {
            return null;
        }

        // When the built assets are served from a separate origin — e.g. the Vapor /
        // CloudFront asset domain exposed via ASSET_URL — 'self' no longer covers the
        // app's own JS/CSS/font bundles, so the browser blocks them. Allow that origin
        // across the asset-loading directives. No-op when ASSET_URL is unset or relative
        // (same-origin), so local/Docker deployments are unaffected.
        if ($assetOrigin = $this->assetOrigin()) {
            foreach (['script-src', 'style-src', 'font-src', 'img-src'] as $d) {
                $directives[$d] = trim(($directives[$d] ?? '')." {$assetOrigin}");
            }
        }

        if (app()->environment('local')) {
            // The Vite dev server serves scripts, styles, fonts (e.g. MDI icon webfont)
            // and images from its own origin, and HMR uses a ws:// socket. Allow those
            // local origins across the relevant directives so the real policy still
            // applies but dev tooling works.
            $devHosts = 'http://localhost:* http://127.0.0.1:*';
            foreach (['script-src', 'style-src', 'font-src', 'img-src'] as $d) {
                $directives[$d] = trim(($directives[$d] ?? '')." {$devHosts}");
            }
            $directives['connect-src'] = trim(($directives['connect-src'] ?? '')." {$devHosts} ws://localhost:* ws://127.0.0.1:*");
        }

        return implode('; ', array_map(
            fn ($directive, $sources) => trim("{$directive} {$sources}"),
            array_keys($directives),
            array_values($directives),
        ));
    }

    /**
     * The scheme://host[:port] origin that serves the built assets, derived from
     * ASSET_URL (config app.asset_url). Returns null when ASSET_URL is unset or has
     * no host (a relative/same-origin value), so the CSP is left untouched.
     */
    private function assetOrigin(): ?string
    {
        $assetUrl = config('app.asset_url');
        if (empty($assetUrl)) {
            return null;
        }

        $host = parse_url($assetUrl, PHP_URL_HOST);
        if (empty($host)) {
            return null;
        }

        $scheme = parse_url($assetUrl, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($assetUrl, PHP_URL_PORT);

        return "{$scheme}://{$host}".($port ? ":{$port}" : '');
    }
}
