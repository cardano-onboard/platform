# QR Codes & Claiming

## Share codes as QR

Every code has a scannable QR that deep-links recipients straight into the claim flow
(via a `web+cardano://claim/...` URI plus the campaign's claim URL).

- **Single code:** click the QR button on a code row to view/print its QR.
- **All codes:** click **Export QR Codes** in the campaign toolbar to open the export
  dialog, then download a ZIP with one uniquely-coded QR per code — drop them on flyers,
  stickers, or event screens. A bundle you have not exported before is rendered in the
  background, and the campaign page shows the run while it works. Asking for the same settings
  again serves the bundle that was already built.

### Export options

The export dialog lets you tailor the output to your printer and stock, with a live
preview of a single sticker:

- **Format** — **PDF** (print-ready, prints cleanly on Windows; the default), **PNG**
  (raster image; available when the server has the GD extension), or **SVG** (vector, for
  design tools).
- **Sticker size** — 1", 1.5", 2", or a custom edge length (0.5"–4"), square.
- **Resolution (DPI)** — 203 (thermal-label default, e.g. Zebra ZP 450), 300, or custom.
  Drives the pixel dimensions of PNG output (`inches × DPI`).
- **Error correction** — L / M / Q / H. Higher levels tolerate more damage/smudging at the
  cost of a denser QR.
- **Header / footer** — optionally print the campaign **expiration date** above and the
  **claim code** below the QR (both off by default).
- **Given to** — export every code, one partner's codes, or the codes that were not given
  to anybody. Each choice shows how many codes it covers. This appears once the campaign
  has partners.
- **One folder per partner** — put each partner's stickers in their own folder inside the
  archive instead of loose at the top level.

Every code gets its **own** QR, so a shared image can't be posted online to drain the
campaign wallet.

### What is in the archive

Every archive carries a `manifest.csv` at the top level with one row per code: the partner
it was generated for, the code, the file it is in, and the claim URL the QR encodes. It
opens in any spreadsheet.

A grouped archive adds two more things. Each partner's folder holds a `manifest.txt` naming
the partner, the number of codes in the folder, when the export was made, an export
reference, and the codes themselves. Codes that were not given to a partner are in a folder
called `unassigned`.

At the top level, `handover.txt` lists every partner with their count and the total. It is
the sheet to print and take to the table: the number beside a partner is the number of
stickers in their folder, and the same number is at the top of that folder's `manifest.txt`,
so a stack can be checked against it without scanning anything.

Folder names come from the partner name, reduced to lowercase letters, digits and hyphens.
Two partners whose names reduce to the same thing keep separate folders, so a count is
never two partners' codes added together. The name as you typed it is in the manifests.

## The claim experience

From the recipient's side:

1. They scan the QR (or open the claim link). The **code is pre-filled** from the QR.
2. They enter their **Cardano wallet address**.
3. They submit, and the reward is queued for delivery to their wallet.

Invalid codes, exhausted codes, and malformed addresses are rejected with a clear
message. Per-wallet and per-IP rate limits protect the endpoint (see
[Configuration](./configuration)).

## Delivery

Claims are processed asynchronously by the queue worker using the configured
[transaction backend](./configuration#transaction-backends). On the `null` backend the
claim is recorded but nothing is sent — useful for rehearsing the flow before mainnet.

You can watch claim statuses and refresh them on demand from the campaign page — see
[Monitoring & performance](./monitoring).
