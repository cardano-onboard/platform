# Social preview cards

How a link to Onboard.Ninja looks when someone pastes it into X, Slack, Discord or iMessage.
Three surfaces, and they do not share an implementation.

## The rule that governs all of it

**Scrapers do not run JavaScript.** A share card is built from the HTML that arrives in the
first response. Inertia's `<Head>` component runs on the client, so anything it sets is
invisible to a scraper no matter how correct it looks in a browser.

Every tag that matters is therefore emitted from `resources/views/app.blade.php`, before the
app boots.

## The application

`resources/views/app.blade.php` renders a default card for the whole app. That default is the
right behaviour here: every campaign page sits behind auth, so a shared link only ever resolves
to the login page and there is nothing per-campaign to preview.

Defaults live in the Blade file. `og:image` goes through `asset()` so it resolves against
`ASSET_URL`. Where a deployment serves `public/` from a separate asset host, a root-relative
path 404s, which is what `SocialMetaTest` guards.

`og:site_name` is written out rather than read from `APP_NAME`. `APP_NAME` is a deployment
setting, so it can differ between environments and from the naming rule in `brand/README.md`.
The brand is not a per-environment concern.

## A page with its own card

Pass a `meta` array as an Inertia prop. Blade picks it up from `$page['props']['meta']` and
falls back to the defaults for anything absent.

```php
return Inertia::render('Ada/Now', [
    'meta' => [
        'title'       => "You've got ADA. Now what?",
        'description' => 'Three things you can do with ADA right now: ...',
        'image'       => 'og-ada-now.png',   // resolved through asset()
        'image_alt'   => 'Describe the image for someone who cannot see it.',
        'type'        => 'article',
    ],
]);
```

`/ada/now` is the one page using this. It is the most shareable thing the project owns, and
before the override existed it advertised itself as the application's login page.

### Making a card image

Cards are 1200 by 630. The source for the `/ada/now` card is
`brand/assets/social/og-ada-now.html`, rendered with headless Chrome:

```
google-chrome --headless --disable-gpu --hide-scrollbars \
  --window-size=1200,630 --virtual-time-budget=6000 \
  --screenshot=public/og-ada-now.png brand/assets/social/og-ada-now.html
```

Keeping the source as HTML means the card is rebuildable when the wording changes, and it
picks up the real brand assets and typefaces rather than an approximation of them.

Two brand rules apply. Headlines are Varela; body and labels are Inter. The ada sign, U+20B3,
must be set in Inter, because Varela has no glyph for it and the character silently falls back
to a system face. See `brand/README.md`.

## Checking a card

Neither X nor Slack re-reads a URL it has already cached, so use the platform's own inspector
after a change rather than pasting the link and trusting what appears. What a scraper sees can
always be read directly:

```
curl -s -A "Twitterbot/1.0" https://app.onbd.io/ada/now | grep -E 'og:|twitter:'
```
