<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Onboard.Ninja') }}</title>

        {{-- Social / SEO meta, server-rendered.

             Scrapers do not run JavaScript, so Inertia's <Head> is invisible to them. Anything
             a share card needs has to be emitted here, before the app boots. A page that wants
             its own card passes a `meta` prop from its controller; everything else falls back
             to the defaults below, which is right for the app itself because every campaign
             page is behind auth and a shared link only ever resolves to the login page.

             The brand name is written out rather than read from APP_NAME. APP_NAME is a
             deployment setting and has drifted before; the brand is not a per-environment
             concern. See brand/README.md for the naming rule.

             og:image uses asset() so it follows ASSET_URL wherever public/ is served from a
             separate asset host rather than the app domain. --}}
        @php($brand = 'Onboard.Ninja')
        @php($meta = $page['props']['meta'] ?? [])
        @php($ogTitle = $meta['title'] ?? $brand)
        @php($ogDescription = $meta['description'] ?? 'Run Cardano airdrops at live events, then find out how many of the wallets that claimed were brand new.')
        @php($ogImage = asset($meta['image'] ?? 'og.png'))
        @php($ogType = $meta['type'] ?? 'website')
        <meta name="description" content="{{ $ogDescription }}" />
        <meta property="og:type" content="{{ $ogType }}" />
        <meta property="og:site_name" content="{{ $brand }}" />
        <meta property="og:title" content="{{ $ogTitle }}" />
        <meta property="og:description" content="{{ $ogDescription }}" />
        <meta property="og:url" content="{{ url()->current() }}" />
        <meta property="og:image" content="{{ $ogImage }}" />
        <meta property="og:image:alt" content="{{ $meta['image_alt'] ?? 'The Onboard.Ninja logo, a ninja face inside the O of the word ONBOARD.' }}" />
        <meta property="og:image:width" content="1200" />
        <meta property="og:image:height" content="630" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="{{ $ogTitle }}" />
        <meta name="twitter:description" content="{{ $ogDescription }}" />
        <meta name="twitter:image" content="{{ $ogImage }}" />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=varela:400&display=swap" rel="stylesheet" />
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        {{-- asset() so the icon follows ASSET_URL where public/ is served from a separate
             asset host rather than the app domain, and the app root where it is not. --}}
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
