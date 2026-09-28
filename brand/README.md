# Onboard.Ninja brand assets

Everything needed to refer to Onboard.Ninja correctly: the logo files, the colours, the
typefaces, and how to describe what the product does.

If you are writing about a campaign you ran with Onboard.Ninja, or listing it among the tools
you use, take what you need from here. No permission is required for that use. The one thing
we ask is that the logo is not altered, because a redrawn logo is the thing readers notice.

Files here are identity only. `VOICE.md` covers how the project writes.

## What to call it

The product is **Onboard.Ninja**. One word each side of a dot, both capitalised.

| Correct | Not this |
|---|---|
| Onboard.Ninja | Onboard Ninja, OnboardNinja, ONBOARD.NINJA, onboard.ninja in prose |

The wordmark reads ONBOARD because the ninja face is the O. That is the logo, not the name.
The domain `onboard.ninja` and the account `@OnboardNinja` are written as they are.

## Describing it

Pick the one that matches your reader. They are not interchangeable: a non-crypto audience and a crypto
audience need different words. All are accurate as of version 1.3.0-beta.

**The core sentence:** Onboard.Ninja lets anyone receive and own digital assets in minutes,
safely, without confusing setup or risky paper wallets.

**For a general audience:** Onboard.Ninja helps people get their first crypto wallet safely.
Scan a code at an event, download one wallet app, and claim something that is genuinely yours.
No accounts, no passwords, nothing to write down and lose.

**For an event organiser:** Onboard.Ninja hands out digital collectibles, passes or rewards to
your attendees by QR code, including attendees who have never owned a wallet. Afterwards it
reads the chain and tells you how many of those wallets were brand new, how many went on to do
something of their own, and how many delegated to a stake pool.

**For a technical audience:** An open-source implementation of CIP-99, the Cardano standard for
onboarding claims, with automated wallet and campaign management and an on-chain onboarding
analysis built in. Available hosted, or self-hosted under Apache 2.0.

Three accuracy notes, because getting these wrong is worse than saying less:

- A **self-hosted** instance keeps claimant addresses, stake keys and the onboarding analysis
  on your own database. It still relies on an external service to submit the transaction to the
  chain. Please do not describe it as fully independent or trustless.
- **Transacted for itself** has a specific meaning here: the wallet later sent a transaction of
  its own. Simply receiving a second payout does not count. Neither does a transaction that
  carried nothing but the wallet's own stake certificate, because a wallet pays the deposit and
  the fee for its own delegation. Delegation is reported as its own figure. The definition is
  deliberately strict so the number cannot be inflated by spending more. Please do not call it
  activation.
- Please do not add investment framing. No price, no value, no returns. It is the thing most likely to lose a
  first-time audience, and it is a rule rather than a preference.

## Logo

The mark is a ninja face. It sits inside the O of the wordmark, and it also stands alone.

| File | Use it on |
|---|---|
| `assets/wordmark-colour-on-light.svg` | White and light backgrounds. The default. |
| `assets/wordmark-colour-on-dark.svg` | Dark backgrounds. |
| `assets/wordmark-mono-ink.svg` | One-colour printing, light background. |
| `assets/wordmark-mono-paper.svg` | One-colour printing, dark background. |
| `assets/wordmark-currentcolor.svg` | Inline in HTML, inherits the surrounding text colour. |
| `assets/wordmark-wide-mono-ink.svg` | Large format, drawn at 1006 by 160. |
| `assets/mark-ink.svg` | Favicon, avatar and app icon, light background. |
| `assets/mark-paper.svg` | Favicon, avatar and app icon, dark background. |
| `assets/mark-orange.svg` | The mark alone where the wordmark will not fit. |
| `assets/mark-currentcolor.svg` | Inline in HTML. |

**Clear space** is one mark-height on every side. Nothing else goes in that band.

**Minimum width** for the wordmark is 96 pixels. Below that the face inside the O closes up.
Use the mark on its own instead.

**Do not** recolour it, rotate it, outline it, add a shadow, stretch it, or rebuild it in
another typeface. Do not place the colour wordmark on a mid-tone background, roughly 30 to 70
percent luminance, where the orange loses contrast. Use a mono variant there.

## Colour

Onboard orange is the brand. Everything else is a surface.

| Role | Value | Notes |
|---|---|---|
| Onboard orange | `#FE5B24` | The brand colour. |
| Orange light | `#FF7A3D` | Hover and accent. |
| Orange dark | `#DC3700` | Pressed and active. |
| Ink | `#262626` | Logo and headings on light. |
| Paper | `#FFFFFF` | Light surface. |
| Dark surface | `#1E1E1E` | Cards on dark. |
| Dark background | `#121212` | Page background on dark. |

`#0033AD` is Cardano's own blue. It appears where the subject is Cardano itself. It is not an
Onboard.Ninja colour and should not be used as one.

Machine-readable values are in `brand.json`.

## Type

**Varela** sets headings. **Inter** sets everything else.

| Role | Family | Stack |
|---|---|---|
| Headings and display | Varela 400 | `Varela, Inter, system-ui, sans-serif` |
| Body, UI, long text | Inter 400/500/600/700 | `Inter, system-ui, sans-serif` |
| Addresses, policy IDs, claim codes, hashes | monospace | `ui-monospace, SFMono-Regular, Menlo, monospace` |

Varela ships a single weight, 400, which is all a heading needs. It is the voice of the
wordmark and it is not for running text.

Inter carries body text for two reasons. It has the weights Varela does not, and it is the
only face in the system containing the ada sign at U+20B3. Verified: Inter v20 has it across
2849 glyphs; Varela v17 does not, across 531; nor does the Figtree latin subset, across 222.
Write the symbol and let it render. Do not spell out ADA where the symbol belongs.

Inter is also the face the project writes its documents in, so a page and a proposal look
like the same organisation made them.

## Voice

`VOICE.md`. The short version: plain words, specific numbers with their denominators, say what
the product cannot do, define every metric where it appears, and no emoji.

## Related

`docs/brand-style-guide.md` is the implementation reference: the full theme token tables, the
semantic colour scales, and how the values map onto Vuetify and Tailwind. It is written for
someone changing the application.

This directory is written for someone outside it. When a brand value changes, both files and
the code change together.
