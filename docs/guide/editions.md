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
| Transaction delivery                        | ✅ (managed)  | ⚠️ Needs a backend |
| **User registration & email verification**  | ✅            | ❌ (admin-seeded) |
| **Password reset**                          | ✅            | ❌                |
| **Profile page**                            | ✅            | ❌                |
| **API tokens (issue)**                      | ✅            | ❌                |
| **Proxy backend (consume)**                 | n/a           | ✅ (uses a SaaS token) |
| Managed infrastructure & backend            | ✅            | You run it        |

## Why the difference?

The self-hosted edition is meant for a single operator (or small team) running their own
instance, so it ships with an **admin account seeded from configuration** rather than open
registration, and omits the multi-user account surfaces (registration, email verification,
password reset, profile, API-token issuance). The publish process strips those routes,
controllers, views, and tests from the public build.

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
