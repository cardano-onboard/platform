<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Onboard.Ninja') }}</title>

        {{-- Social / SEO meta. A single static default for the whole app: campaign pages are
             admin-only (behind auth), so a shared link only ever resolves to the login/marketing
             page — there's nothing per-campaign to preview. Scrapers don't run JS, so these must
             live in the server-rendered head (not Inertia's client-side head). og:image uses
             asset() so it resolves to the CloudFront ASSET_URL on Vapor (public/ is served from the
             CDN there, not the app domain). og.png is the designed 1200x630 share image. --}}
        @php($ogImage = asset('og.png'))
        @php($ogDescription = 'Ninja-fast Cardano airdrops for your event.')
        <meta name="description" content="{{ $ogDescription }}" />
        <meta property="og:type" content="website" />
        <meta property="og:site_name" content="{{ config('app.name', 'Onboard.Ninja') }}" />
        <meta property="og:title" content="{{ config('app.name', 'Onboard.Ninja') }}" />
        <meta property="og:description" content="{{ $ogDescription }}" />
        <meta property="og:url" content="{{ url()->current() }}" />
        <meta property="og:image" content="{{ $ogImage }}" />
        <meta property="og:image:width" content="1200" />
        <meta property="og:image:height" content="630" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="{{ config('app.name', 'Onboard.Ninja') }}" />
        <meta name="twitter:description" content="{{ $ogDescription }}" />
        <meta name="twitter:image" content="{{ $ogImage }}" />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=varela:400&display=swap" rel="stylesheet" />
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        {{-- asset() so the icon resolves to the CloudFront ASSET_URL on Vapor (public/ is
             served from the CDN there, not the app domain) and to the app root locally. --}}
        <link rel="icon" sizes="any" type="image/png" href="{{ asset('favicon.png') }}" />
        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
