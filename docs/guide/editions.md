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
| Transaction delivery                        | ✅ (managed)  | ⚠️ Needs a backend |
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

Everything up to the point of paying a claimant runs entirely on your own instance. Putting
the transaction on chain does not, yet. A self-hosted instance needs one of:

- **`TRANSACTION_BACKEND=phyrhose`** — talks to the hosted transaction service directly, and
  needs credentials for it.
- **`TRANSACTION_BACKEND=proxy`** — relays through the hosted platform with a
  `PROXY_API_TOKEN` generated on a SaaS account, giving you managed delivery without running
  a Cardano node. See the [API reference](./api-reference#proxy-api-saas-sanctum).

There is currently **no option that submits transactions without one of those two**. Running
the platform fully independently would require it to build, sign and submit its own
transactions, which is
[tracked on the roadmap](https://github.com/cardano-onboard/.github/issues/23) and not yet
built. If that independence is why you are self-hosting, it is better to know now.

::: warning The null backend is not a third option
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
