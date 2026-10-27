# Editions: SaaS vs Self-hosted

Onboard.Ninja ships as one codebase in two editions. The **core workflow is identical** —
campaigns, funding, codes, reward tokens, QR codes, claiming, monitoring, and refunds work
the same way in both. The differences are in account management and infrastructure.

## Feature comparison

| Capability                                  | SaaS (hosted) | Self-hosted (DIY) |
|---------------------------------------------|:-------------:|:-----------------:|
| Campaigns, codes, QR, claiming, refunds     | ✅            | ✅                |
| Native-token rewards + known-asset import   | ✅            | ✅                |
| Performance charts & reward details         | ✅            | ✅                |
| Campaign cost statement (fees, network cost, rewards given away) | ✅ | ✅          |
| Operator alerts (warns when a campaign is running short) | ✅      | ✅                |
| Transaction delivery                        | ✅ (managed)  | ✅ Built in        |
| **User registration & email verification**  | ✅            | ❌ (admin-seeded) |
| **Password reset**                          | ✅            | ❌                |
| **Profile page**                            | ✅            | ❌                |
| **Proxy API tokens (issue)**                | ✅            | ❌                |
| **Code API tokens (issue via `api:token`)** | ✅            | ✅                 |
| **Proxy backend (consume)**                 | n/a           | ✅ (uses a SaaS token) |
| **Claim pricing, credits & billing**        | ✅            | ❌                |
| **Spend limits & held claims**              | ✅            | ❌                |
| **Admin platform metrics**                  | ✅            | ❌                |
| **First-party source codes**                | ✅            | ❌                |
| Managed infrastructure & backend            | ✅            | You run it        |

## Why the difference?

The self-hosted edition is meant for a single operator (or small team) running their own
instance, so it ships with an **admin account seeded from configuration** rather than open
registration, and omits the multi-user account surfaces (registration, email verification,
password reset, profile, and the profile page's own token issuance). The publish process
strips those routes, controllers, views, and tests from the public build. It does not
strip `api:token`: a self-hosted operator still needs a way to mint a code API token,
just from a console command rather than a page.

## Hosted-only features

The rows above marked hosted-only are about running many operators on one shared deployment,
so a single self-hosted instance has no use for them.

The campaign cost statement and the running-short alert are not part of that: they are about
one campaign an operator is already running rather than the deployment as a whole, and both
ship in full on a self-hosted install too. See
[Campaigns & funding](./campaigns#what-this-campaign-cost) and
[Warnings when it runs short](./campaigns#warnings-when-it-runs-short).

## Transaction delivery on a self-hosted instance

A self-hosted instance submits its own transactions. By default (`TRANSACTION_BACKEND=native`)
each campaign gets its own signing key and its own address, and the instance builds, signs and
submits every payment itself, with no transaction service holding its keys or funds. Chain reads and
submission go to Koios, its public endpoints or your own through the `KOIOS_*_URL` settings. You supply a wrapping key, two
recovery key hashes from wallets you hold, and a stake address once, before the first campaign;
[Configuration](./configuration#native-campaign-wallets) walks through each.

Two alternatives are still available if you would rather not hold keys on your own server:

- **`TRANSACTION_BACKEND=phyrhose`** — talks to the hosted transaction service directly, and
  needs credentials for it.
- **`TRANSACTION_BACKEND=proxy`** — relays through the hosted platform with a
  `PROXY_API_TOKEN` generated on a SaaS account, giving you managed delivery without running
  a Cardano node. See the [API reference](./api-reference#proxy-api-saas-sanctum).

::: warning The null backend is not a delivery option
`TRANSACTION_BACKEND=null` records claims and sends nothing on chain. It exists for testing
the flow without spending anything, and it is what you get when the variable is unset. The
application makes this obvious while it is active, with a red test-mode bar on every page and
a warning that the wallet addresses shown are fake, so you will not run a real campaign on it
by accident.
:::

## Which should I use?

- **Want the fastest path, no ops?** Use **SaaS** — [sign up](./getting-started-saas).
- **Want full control / open source?** Run **self-hosted** —
  [install with Docker](./getting-started-self-hosted).
