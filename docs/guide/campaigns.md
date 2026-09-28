# Campaigns & Funding

A **campaign** is the container for an airdrop: its network, claim window, codes, and a
dedicated **bucket wallet** that funds the rewards.

## Create a campaign

1. From the **Dashboard**, choose **Create Campaign**.
2. Enter a **name** and an optional **description**.
3. Pick a **network** — `preprod` (default, for testing), `preview`, or `mainnet`.
4. Set the **start** and **end** dates for the claim window.
5. Optionally set **one-per-wallet** and a transaction message.
6. Save.

The network list in step 3 holds only the networks this deployment accepts. See
[Allowed networks](./configuration#allowed-networks).

On save, the platform provisions a **bucket wallet** for the campaign. You'll briefly see
a *"wallet provisioning"* notice; once ready, the campaign page shows the wallet address
and a live status chip (active / upcoming / ended / draft).

## Fund the bucket wallet

The bucket must hold enough ADA and tokens to cover every reward you intend to deliver.

1. On the campaign page, click **Top Up** (shown when a CIP-30 wallet is detected).
2. **Connect** a browser wallet (Eternl, Lace, etc.) on the **same network** as the
   campaign.
3. Click **Show Details** to see exactly what the bucket still needs — required lovelace
   and a per-token breakdown.
4. Review the amounts and **confirm** the transaction in your wallet. Each token is sent
   to its own UTxO so it can be distributed cleanly.
5. A success toast confirms submission and the balance updates.

### Partial funding

If your wallet is missing some of a required token, the platform tells you, sends what
you *do* have, and defers the shortfall — so you can fund in multiple passes.

### Warnings when it runs short

Two controls in the campaign settings dialog decide this. **Warn me when this campaign is
running short** turns the warning on and off, and **Warn me with this many claims left**
is how much warning you want. Leave the second blank to use the default of ten.

The threshold counts claims rather than ADA, so it means the same thing however the
campaign is billed: how many more people can claim before this stops working. Where the
figure can be worked out, the dialog shows how many the campaign can currently serve, so
you can compare the number you type against it.

That figure comes from the most expensive code that can still be claimed, plus the fees
that go with a claim, rather than the average of them. The next person through the door
might be holding that code.

A campaign is only short while it is active. Before it starts there is nothing to be short
of, and after it ends nothing more will be claimed.

## Refund unclaimed funds

When a campaign ends, click **Refund Bucket** to return the remaining ADA and tokens to
your wallet. Nothing is stranded.

## What this campaign cost

The **Costs** tab, beside Performance, Onboarding, Wallets and Partners, breaks down what a
campaign has spent: network fees paid to the chain, and rewards given away. Figures are read
from what each claim recorded at the time it happened, not from today's rates, so a claim
taken under an earlier rate still reports what it was actually charged.

Native assets given away are listed by policy underneath the totals, and **Export CSV**
lists every asset on its own row with its policy, name and quantity. A claim taken before
this campaign started recording a cost is counted separately rather than as free.

## Next

Create the codes recipients will redeem → [Codes & reward tokens](./codes-and-rewards).
