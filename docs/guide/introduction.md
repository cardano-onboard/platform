# Introduction

**Onboard.Ninja** is a platform for running Cardano token airdrops. You create a
**campaign**, generate **claim codes**, and share them (often as QR codes). Recipients
enter a code and their wallet address, and the platform delivers the reward — ADA and/or
native tokens — to their wallet.

It's designed for events, community drops, and onboarding new users to Cardano without
asking them to understand smart contracts.

## How it works

1. **Create a campaign** — pick a network and claim window. The platform provisions a
   dedicated *bucket wallet* to fund rewards from.
2. **Fund the bucket** — top it up from your own CIP-30 browser wallet (Eternl, Lace,
   etc.) with the ADA and tokens you'll distribute.
3. **Generate codes** — create one code or thousands at once, each with its own usage
   limits and reward configuration.
4. **Share QR codes** — download print-ready QR codes that deep-link recipients straight
   into the claim flow.
5. **Recipients claim** — they scan, enter their wallet address, and receive the reward.
6. **Monitor & refund** — watch claims roll in on the performance charts, then refund any
   unclaimed balance when the campaign ends.

## Two editions

Onboard.Ninja comes in two flavors that share the same core app:

- **SaaS** — the hosted service. Sign up, and we run the infrastructure and transaction
  backend for you. See [Sign up (SaaS)](./getting-started-saas).
- **Self-hosted** — the open-source platform you run yourself with Docker. See
  [Install (Self-hosted)](./getting-started-self-hosted).

The differences are summarized in [Editions](./editions).

## Networks

Campaigns run on **preprod** (recommended for testing) or **mainnet**. Always trial a
campaign on preprod before spending real funds on mainnet.
