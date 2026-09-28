# Codes & Reward Tokens

**Claim codes** are what recipients redeem. Each code carries a reward (ADA and/or native
tokens) and usage limits.

## Generate codes

On the campaign page, choose **Create Code** and set:

- **Partner** — who this batch is being handed to: a member of staff on a booth, another
  project, a print run, a mailing. Pick one you have used before, add a new one by name,
  or leave it on **Unassigned**.
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
new ones (subject to the configured file-size and code-count limits). An import asks who
the codes are for in the same way a generated batch does.

## Partners

A partner is recorded when the batch is generated, and it cannot be changed afterward: a
code moved to somebody else after its claims are in was not necessarily handed out by them.

Once a campaign has used one, the codes table gains a **Partner** column and a filter over
it, so you can see who each batch went to and narrow the table to one partner's codes. The
claims export carries the partner too.

Two partners on one campaign cannot share a name, and case does not make them different:
type `vendor a` where `Vendor A` already exists, and you are told the name is taken.
Removing a partner takes it off the list you pick from. Its name stays on the codes it was
already given.

The import runs in the background. The campaign page shows how far through the file it is
and refreshes the codes table when it finishes. If the import cannot run, because the file
is too large or is not the JSON the importer expects, the page says so and gives the reason.

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

## How much ADA a token reward needs

Every Cardano output has to hold a minimum amount of ADA, and that minimum rises with what
is in the output: each policy, each asset and every character of an asset name is more for
the ledger to store. One token under one policy needs roughly 1.16 ADA. A bundle spread
across several policies needs more.

The code form works the minimum out from the tokens you have added and states it under the
**Lovelace** field, along with a suggested amount. A code set below the minimum is refused,
because the payment could never be submitted: the code would print, scan and fail silently
at a booth.

A code that clears the minimum with nothing left over is allowed, and warned about. The
recipient can receive it and cannot spend it, because moving a token costs a fee and the
output is not allowed to drop below its own floor. Somebody claiming on a wallet they
installed at your table has no other ADA to draw on, so their token stays where you sent
it. The suggested figure is the minimum plus a whole ADA of room, which is enough to cover
a fee several times over.

The campaign page counts any existing codes that are under their minimum or on it, so
codes created before this check existed can be found and raised.

## Change a code's reward

A code's reward can be changed after the code exists. Use the pencil on its row in the
codes table to open **Change Reward**, then set the lovelace, add or remove tokens, and
save. The code, its QR and its usage limits are untouched, so anything already printed
keeps working.

**Changes apply forward.** A claim records what it was actually paid at the moment the
payment went out, so a code with ten uses and three claims against it can be changed
without altering what those three claimants received. The dialog says how many claims from
that code have already been paid and will keep what they were sent.

One case is not forward, and the dialog names it: a claim that has been accepted but not
sent yet. Those claimants have already been shown what they were promised, and they will
receive whatever the code says when the payment is submitted. Rewards are sent in batches a
few minutes after a claim, so this is a short window rather than a theoretical one.

An edit is checked against the same minimum a new code is. A reward below what the chain
will accept is refused, and one that leaves the recipient nothing to spend is saved with a
warning.

The campaign's funding figures are recalculated from the new reward, so **still needed**
reflects the change immediately.

What each claim was paid appears in the claims export as `reward_lovelace` and
`reward_tokens`. Both are blank for a claim that has not been sent yet, and for claims taken
before the platform recorded rewards: blank means unrecorded, which is not the same as a
reward of nothing. Nothing is backfilled, because a figure worked out now would be today's
configuration rather than a record.

## Next

Share your codes → [QR codes & claiming](./claiming).
