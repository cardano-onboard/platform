# Monitoring & Performance

The campaign page gives you a live read on how an airdrop is performing.

## Performance charts

Above the codes table you'll find three cards:

- **Claims Over Time** — a sparkline of cumulative claims, with the total to date.
- **Reward Slots** — claimed vs. unclaimed reward slots across all codes, as a percentage
  and counts.
- **Code Utilization** — how many codes are claimed, unclaimed, available, or exhausted.

These appear once a campaign has codes and update as claims come in.

## Per-code reward details

Expand any row in the codes table to see its **reward details**: the ADA amount plus each
native token with a **human-readable name** (resolved from the known-asset registry /
Koios), the **quantity** (formatted using the token's decimals), and the policy ID. Tokens
the registry recognizes also show their ticker and logo.

## Refreshing claim status

Claims are delivered asynchronously and their on-chain status is checked periodically by
the scheduler. To refresh immediately, click **Check claims** above the codes table, and
the platform re-queries the backend and updates each claim's status. The check runs in the
background and shows on the campaign page with a count of the claims it has asked about.
When it finishes, the claims on the page update without a reload. If it could not finish,
the page says why. Only one check runs per campaign at a time, so the button stays busy
until the current one is done. The onboarding panel offers the same check for claims it
cannot see, and running an onboarding analysis runs the check first either way.

## Refunds

When the campaign is over, use **Refund Bucket** to recover the remaining balance. See
[Campaigns & funding](./campaigns#refund-unclaimed-funds).

## Beyond the dashboard

For programmatic monitoring and integration, see the [API reference](./api-reference).
