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
- **NMKR Project UID / NFTs Per Claim** — optional, to mint an NFT on claim. The NMKR API key is the one entered on the campaign.

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
the ledger to store. One token under one policy needs about 1.15 ADA. A bundle spread
across several policies needs more.

A reward is stored as the higher of the ADA you enter and the exact minimum for its tokens,
so you never need to work the minimum out yourself. Set the reward to 1 ADA and add a token,
and the reward rises to the minimum for that token, about 1.15 ADA for one. Set it to 10 ADA
and add a token or two, and it stays at 10 ADA. The rule is the same wherever a code is made:
the code form, the edit dialog, a bulk import and the code API.

The code form shows the minimum under the **Lovelace** field and moves the field up to it as
you add tokens. An amount already above the minimum is left alone, and nothing is ever added
on top. The 1 ADA floor on the field still applies. A code made from a bulk import file is
raised the same way, so a file that lists 1 ADA alongside a token is imported at the minimum
rather than skipped.

Codes created before this rule can still sit below their minimum. The campaign page counts
any that are, so they can be found and opened in the edit dialog, which raises them to the
minimum.

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

An edit follows the same rule as a new code: a reward below the minimum for its tokens is
saved at the minimum, and the confirmation says so.

The campaign's funding figures are recalculated from the new reward, so **still needed**
reflects the change immediately.

What each claim was paid appears in the claims export as `reward_lovelace` and
`reward_tokens`. Both are blank for a claim that has not been sent yet, and for claims taken
before the platform recorded rewards: blank means unrecorded, which is not the same as a
reward of nothing. Nothing is backfilled, because a figure worked out now would be today's
configuration rather than a record.

## Next

Share your codes → [QR codes & claiming](./claiming).
