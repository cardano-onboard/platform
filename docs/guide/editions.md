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
| Transaction backends (null / phyrhose)      | ✅            | ✅                |
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

A self-hosted instance can still **relay through the hosted backend** by setting
`TRANSACTION_BACKEND=proxy` with a `PROXY_API_TOKEN` generated on a SaaS account — giving
you managed transaction delivery without running your own Cardano node. See the
[API reference](./api-reference#proxy-api-saas-sanctum).

## Which should I use?

- **Want the fastest path, no ops?** Use **SaaS** — [sign up](./getting-started-saas).
- **Want full control / open source?** Run **self-hosted** —
  [install with Docker](./getting-started-self-hosted).
