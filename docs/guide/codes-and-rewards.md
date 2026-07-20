# Codes & Reward Tokens

**Claim codes** are what recipients redeem. Each code carries a reward (ADA and/or native
tokens) and usage limits.

## Generate codes

On the campaign page, choose **Create Code** and set:

- **Quantity** — how many codes to generate at once (1–500). Each gets a unique ID and
  shares the reward configuration below.
- **Uses** — how many times the code can be claimed (`0` = unlimited; not recommended).
- **Claims Per Wallet** — per-wallet limit (`1` = one-per-wallet; `0` = unlimited).
- **Lovelace** — the ADA reward (1 ADA = 1,000,000 lovelace).
- **NMKR Project UID / NFTs Per Claim** — optional, to mint an NFT on claim.

Submit, and the codes appear in the table. You can filter by **All / Claimed / Unclaimed
/ Available / Exhausted** and search.

### Bulk import

Already have a list of codes? Use **Import Codes** to upload them instead of generating
new ones (subject to the configured file-size and code-count limits).

## Add native-token rewards

Beyond ADA, a code can distribute native tokens. In the code form, choose **Add Token**:

### Pick a known token (recommended)

Use the **"Find a known token"** search and type a ticker or name — e.g. `HOSKY` or
`USDM`. Selecting it auto-fills the policy ID, asset name, and the token's **decimals**
(which drive correct amount formatting). No hex required.

### Look up a new token

For a token that isn't in the registry yet, paste its **Policy ID** (and asset name if
any) and click **Look up**. The platform resolves it on-chain via Koios, fills in the
details, and saves it to the shared registry so it's searchable by ticker next time.

> **Decimals are display-only.** On-chain quantities are always integers; decimals only
> affect how amounts are shown. Enter the **quantity** in the token's base units.

Set the **quantity** and add the token. Repeat for multiple tokens, then create the code.

## Next

Share your codes → [QR codes & claiming](./claiming).
