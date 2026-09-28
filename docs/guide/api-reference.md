# API Reference

Onboard.Ninja exposes a public **claim API** and (on SaaS) a Sanctum-authenticated
**proxy API**.

## Claim API (public)

Used by the claim page / QR deep link to redeem a code.

```
POST /api/claim/v1/{campaign}
```

| Field      | Required | Description                                  |
|------------|----------|----------------------------------------------|
| `code`     | yes      | The claim code being redeemed.               |
| `address`  | yes      | Recipient's Cardano (bech32) wallet address. |

The endpoint validates the code (existence, remaining uses, per-wallet limit) and the
address (bech32 charset and length), then records a claim and queues delivery via the
configured backend. It is rate-limited per IP and per campaign
(see [Configuration](./configuration#limits-rate-limiting)).

Typical rejections: unknown/exhausted code, invalid address, nonexistent campaign.

### Claiming on an attendee's behalf

An application that submits a claim for the attendee itself — something that makes the
CIP-99 claim server-side rather than the attendee's own wallet — can authenticate the
request with a bearer token carrying the `codes:claim` ability, scoped to the campaign the
same way `codes:create` and `codes:status` are (the token's account has to own the
campaign):

```
Authorization: Bearer {id}|{token}
```

Doing so changes two things about the request, and nothing else about how it is handled:

- The claim is rate-limited by the token's own account (`CLAIM_RATE_PER_TOKEN`, default
  300/min) instead of by IP and by campaign, since every claim the same backend submits
  otherwise shares one IP. The limit is shared across every `codes:claim` token the
  account holds, so minting a second token never doubles the budget.
- An `X-Claim-Client-User-Agent` header, if sent, is recorded as the claiming client in
  place of the request's own user agent, so the tally still reflects the attendee's wallet
  rather than the backend that relayed the claim.

A request with no token, an expired or unrecognised token, or a token without the
`codes:claim` ability is treated exactly like any other public claim: both the IP and
campaign limits apply, and any `X-Claim-Client-User-Agent` header is ignored.

### Claim deep link

QR codes encode a `web+cardano://claim/v1?...` URI alongside the campaign claim URL so
compatible wallets/handlers can launch the claim flow directly.

## Code API (Sanctum)

Lets an external event application make a code for an identity it already knows about the
moment that identity needs one, instead of every code being generated in advance, and ask
what happened to a code it made. Ships in both editions: unlike the proxy API below, a
self-hosted instance can issue its own code API tokens with the `api:token` command (see
[Console commands](./configuration#console-commands)).

Authenticate with a bearer token, the same as the proxy API:

```
Authorization: Bearer {id}|{token}
```

Each endpoint requires its own ability, so a token minted for one can never reach the
other:

| Ability         | Endpoint                                          |
|-----------------|----------------------------------------------------|
| `codes:create`  | `POST /api/v1/campaigns/{campaign}/codes`           |
| `codes:status`  | `GET /api/v1/campaigns/{campaign}/codes/{code}/status` |
| `codes:claim`   | `POST /api/claim/v1/{campaign}` (see [Claiming on an attendee's behalf](#claiming-on-an-attendees-behalf)) |

### Create a code

```
POST /api/v1/campaigns/{campaign}/codes
```

| Field       | Required | Description                                                        |
|-------------|----------|----------------------------------------------------------------------|
| `reference` | yes      | The caller's own key for whoever this code is for, up to 191 characters from `A-Z a-z 0-9 . _ : -`. |
| `lovelace`  | yes      | The code's ADA payment, subject to the same minimum-UTxO floor as the campaign page's own form. |
| `tokens`    | no       | An array of reward tokens, each `{policy_id, token_id, quantity}`. `policy_id` must be exactly 56 hex characters, `token_id` even-length hex of at most 64 characters, and `quantity` at least 1. |

`reference` makes a retried request safe: sending the same reference again returns the
code already made for it (`200`) instead of making a second one. Sending it again with a
different `lovelace` amount or a different token set is treated as a second, different
request that happens to reuse a name already spent, and is refused with `409` rather than
silently handed the first code.

A successful create returns `201` with `{"code": "..."}` (or `200` on an idempotent
replay). Rejections: `422` for a failed validation rule, a campaign that has ended, or a campaign whose operator-set `max_codes`
cap would be exceeded (see [Campaign settings](./configuration)); `404` for a campaign
the token's account does not own.

`tokens` is capped at `CODE_API_MAX_TOKENS_PER_CODE` entries, and a single token's
`quantity` at `CODE_API_MAX_TOKEN_QUANTITY`
(see [Limits & rate limiting](./configuration#limits-rate-limiting)).

### Check a code's status

```
GET /api/v1/campaigns/{campaign}/codes/{code}/status
```

`{code}` is the code string returned by the create endpoint, never a database id. Returns:

| Field              | Description                                                            |
|--------------------|--------------------------------------------------------------------------|
| `claimed`          | Whether any wallet has claimed this code yet.                          |
| `stake_key`        | The claiming wallet's stake key, or `null` when unclaimed.             |
| `address`          | The claiming wallet's address, or `null` when unclaimed.               |
| `status`           | The claim's delivery status (`pending`, `completed`, `failed`), or `null` when unclaimed. |
| `held_reason`      | Why a claim is being held rather than sent, or `null` when it is not held. A self-hosted deployment does not hold claims, so this is always `null` there. |
| `transaction_hash` | The on-chain transaction hash once the reward has landed, or `null` until then. |
| `claimed_at`       | When the claim was taken, or `null` when unclaimed.                    |

Where a code has been claimed more than once, the most recent claim is what is reported.

Both endpoints are rate-limited per token, create and status counted separately, so
polling status cannot use up a token's ability to create the next code
(see [Limits & rate limiting](./configuration#limits-rate-limiting)).

## Proxy API (SaaS, Sanctum)

The proxy API lets a **self-hosted instance relay transactions through the hosted
backend** — set `TRANSACTION_BACKEND=proxy` and provide a `PROXY_API_TOKEN`. Create the
token on your SaaS **Profile → API Tokens** page (it's shown once — copy it immediately).

Authenticate with a bearer token:

```
Authorization: Bearer {id}|{token}
```

Endpoints (all under `/api/v1/proxy`, Sanctum-authenticated):

| Method | Endpoint                | Purpose                          |
|--------|-------------------------|----------------------------------|
| GET    | `/balance`              | Bucket/account balance           |
| POST   | `/payment`              | Submit a payment/transaction     |
| GET    | `/status/{id}`          | Transaction status               |
| POST   | `/refund`               | Refund unclaimed funds           |

Proxy usage counts against your **monthly quota** (`PROXY_MONTHLY_LIMIT`, default 1000).
Unauthenticated or invalid-token requests return `401`.

> The proxy API, and the profile-page tokens that authenticate it, are **SaaS-only**. The
> self-hosted edition consumes a proxy token (as a client) but does not issue one; it does
> issue its own code API tokens, with `api:token`. See [Editions](./editions).
