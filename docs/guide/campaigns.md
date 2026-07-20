# Campaigns & Funding

A **campaign** is the container for an airdrop: its network, claim window, codes, and a
dedicated **bucket wallet** that funds the rewards.

## Create a campaign

1. From the **Dashboard**, choose **Create Campaign**.
2. Enter a **name** and an optional **description**.
3. Pick a **network** — `preprod` (default, for testing) or `mainnet`.
4. Set the **start** and **end** dates for the claim window.
5. Optionally set **one-per-wallet** and a transaction message.
6. Save.

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

## Refund unclaimed funds

When a campaign ends, click **Refund Bucket** to return the remaining ADA and tokens to
your wallet. Nothing is stranded.

## Next

Create the codes recipients will redeem → [Codes & reward tokens](./codes-and-rewards).
