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
| Inline logo component | `resources/js/Components/LogoSvg.vue` | "ONBOARD" wordmark + ninja mark, rendered monochrome in brand orange `#FE5B24` |
| Favicon | `public/favicon.ico`, `public/favicon.png` | Browser tab / bookmark icon |

**Usage**
- Prefer `LogoSvg.vue` or `logo.svg` over the PNG wherever vector rendering is possible.
- The inline logo is single-color brand orange (`#FE5B24`); keep it on backgrounds with
  sufficient contrast (white/light or the dark surface `#1E1E1E`).
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

| Role | Family | Weights | Source |
|------|--------|---------|--------|
| Primary | **Varela** | 400 | bunny.net |
| Secondary / fallback | **Figtree** | 400, 500, 600 | bunny.net |
| System fallback | Tailwind default sans stack | — | local |

- Tailwind font stack (`tailwind.config.js`): `['Varela', 'Figtree', ...defaultTheme.fontFamily.sans]`,
  applied via the `font-sans` utility.
- Fonts are loaded in `resources/views/app.blade.php` via bunny.net with `preconnect`:
  ```html
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=varela:400&display=swap" rel="stylesheet" />
  <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
  ```
- **Varela ships a single weight (400).** Use Figtree (400/500/600) where you need
  medium/semibold emphasis.

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
