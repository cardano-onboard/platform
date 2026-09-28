# Onboard.Ninja — Brand & Theme Style Guide

This is the canonical reference for Onboard.Ninja's visual identity: colors, typography,
logo usage, and the application theme tokens.

> **Source of truth.** The values below are derived from, and must stay in sync with, the
> code that actually renders the app:
> - Vuetify themes → `resources/js/app.js`
> - Tailwind tokens (colors, fonts) → `tailwind.config.js`
> - Web-font loading → `resources/views/app.blade.php`
>
> When you change a brand value, update the code **and** this document together.

---

## Logo & Marks

| Asset | Path | Notes |
|-------|------|-------|
| Primary logo (SVG) | `resources/js/img/logo.svg` | Preferred for in-app use (scalable) |
| Primary logo (PNG) | `resources/js/img/logo.png` | Raster fallback |
| Inline logo component | `resources/js/Components/LogoSvg.vue` | "ONBOARD" wordmark + ninja mark. Two-tone: the six letterforms are brand orange `#FE5B24`, the ninja face inherits `currentColor` so it follows the surrounding text |
| Favicon | `public/favicon.ico`, `public/favicon.png` | Browser tab / bookmark icon |

**Usage**
- Prefer `LogoSvg.vue` or `logo.svg` over the PNG wherever vector rendering is possible.
- The inline logo is two-tone and adapts: the letters stay brand orange (`#FE5B24`) and the
  ninja face takes `currentColor`. Keep it on backgrounds with sufficient contrast
  (white/light or the dark surface `#1E1E1E`), and avoid mid-tone backgrounds where the
  orange loses contrast.
- Third parties needing standalone files should be pointed at `brand/assets/`, which carries
  fixed-colour variants for light, dark and one-colour printing.
- Do not recolor, stretch, or add effects to the wordmark.

---

## Color Palette

### Brand Orange (primary)
The core identity color and its scale (Tailwind `brand.*`).

| Token | Hex | Swatch role |
|-------|-----|-------------|
| `brand.50`  | `#FFF3ED` | Lightest tint (subtle backgrounds) |
| `brand.100` | `#FFE4D4` | Light tint |
| `brand.light` / `accent` | `#FF7A3D` | Hover / accent |
| `brand.DEFAULT` / `brand.500` | `#FE5B24` | **Primary brand color** |
| `brand.dark` / `brand.600` | `#DC3700` | Pressed / darker emphasis |
| `brand.700` | `#C03A00` | Darkest step |

### Neutrals / Dark
Tailwind `dark.*`.

| Token | Hex |
|-------|-----|
| `dark.light` | `#2D2D2D` |
| `dark.DEFAULT` | `#1A1A1A` |
| `dark.900` | `#111111` |

### Semantic colors
Defined per Vuetify theme (see the theme tables below). Light and dark themes use
slightly different tints of each so they remain legible on their respective surfaces.

| Role | Light (`onboard`) | Dark (`onboard_dark`) |
|------|-------------------|-----------------------|
| error   | `#D32F2F` | `#EF5350` |
| info    | `#1976D2` | `#42A5F5` |
| success | `#388E3C` | `#66BB6A` |
| warning | `#F9A825` | `#FFA726` |

---

## Application Themes (Vuetify)

Two themes are registered in `resources/js/app.js`. The **default is `onboard_dark`**
(persisted per-user via `localStorage['theme']`).

### `onboard` (light)

| Token | Hex |
|-------|-----|
| primary | `#FE5B24` |
| secondary | `#1A1A1A` |
| accent | `#FF7A3D` |
| error | `#D32F2F` |
| info | `#1976D2` |
| success | `#388E3C` |
| warning | `#F9A825` |
| background | `#FFFFFF` |
| surface | `#FFFFFF` |
| on-primary | `#FFFFFF` |
| on-secondary | `#FFFFFF` |

### `onboard_dark` (dark — default)

| Token | Hex |
|-------|-----|
| primary | `#FE5B24` |
| secondary | `#B0B0B0` |
| accent | `#FF7A3D` |
| error | `#EF5350` |
| info | `#42A5F5` |
| success | `#66BB6A` |
| warning | `#FFA726` |
| background | `#121212` |
| surface | `#1E1E1E` |
| on-primary | `#FFFFFF` |
| on-secondary | `#000000` |

> `primary`, `accent`, and the brand orange are identical across both themes — only the
> neutral/semantic tints and surfaces differ. The Inertia progress bar is also `#FE5B24`.

---

## Typography

Two families, with distinct jobs. See `brand/brand.json` for the same values in
machine-readable form.

| Role | Family | Weights | Source |
|------|--------|---------|--------|
| Headings and display | **Varela** | 400 | bunny.net |
| Body, UI, everything else | **Inter** | 400, 500, 600, 700 | bunny.net |
| System fallback | Tailwind default sans stack | — | local |

Tailwind stacks (`tailwind.config.js`):

```js
sans:    ['Inter', ...defaultTheme.fontFamily.sans],           // font-sans
display: ['Varela', 'Inter', ...defaultTheme.fontFamily.sans], // font-display
```

Fonts are loaded in `resources/views/app.blade.php` via bunny.net with `preconnect`:

```html
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=varela:400&display=swap" rel="stylesheet" />
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
```

- `<body class="font-sans">` in `app.blade.php` is the only place a family is set, and
  everything inherits from it. A base rule in `resources/css/app.css` gives `h1` through `h6`
  the display stack, which is the only reason Varela appears at all. **If you remove that
  rule, the brand face disappears from the entire application.**
- **Varela ships a single weight (400).** It is a heading face. Anywhere needing 500 or 600
  is body text and belongs in Inter.
- **Inter is the only face here containing the ada sign, U+20B3.** Verified against Inter v20
  (2849 glyphs), Varela v17 (531 glyphs, no U+20B3) and the Figtree latin subset bunny.net
  used to serve (222 glyphs, no U+20B3). Write the symbol; do not spell out ADA in its place.
- Inter is also what the project writes its documents in, so written material and the product
  match.
- **Figtree was removed.** It existed to supply the weights Varela lacks, and Inter supplies
  those plus the ada sign, so it no longer earned a request.

---

## Usage Reference

**Vuetify components** — reference theme tokens, never hardcode hex:
```vue
<v-btn color="primary">Create Campaign</v-btn>
<v-alert type="error">…</v-alert>   <!-- resolves to the active theme's error color -->
```

**Tailwind utilities** — use the named brand scale:
```html
<div class="bg-brand text-white">…</div>
<span class="text-brand-dark">…</span>
<section class="bg-dark-900">…</section>
```

**Avoid** hardcoding `#FE5B24` (and friends) in component templates. The logo SVGs are the
only sanctioned place raw brand hex appears.

---

## Maintenance Notes
- Keep this file, `resources/js/app.js`, and `tailwind.config.js` in lockstep.
- The brand orange has two "dark" steps by design: `brand.dark`/`brand.600` = `#DC3700`
  (standard pressed/emphasis) and `brand.700` = `#C03A00` (darkest). Pick `600` unless you
  specifically need the darkest step.
- This guide ships with the public DIY platform repo (via `scripts/publish-platform.sh`),
  so it doubles as brand guidance for self-hosters and contributors.

---

## Related

`brand/` holds the outward-facing kit: standalone logo files in fixed colours, naming rules,
approved product descriptions, and `brand.json` with the same values in machine-readable form.
It is written for someone outside the project who needs to refer to Onboard.Ninja correctly.

This document is the implementation reference. Both ship to the public repo, and both change
when a brand value changes.
