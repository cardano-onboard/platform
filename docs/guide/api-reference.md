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

### Claim page

A `GET` to the same address returns a browser page. Which page depends on the campaign's
**desktop claims** setting, which is off by default and is turned on per campaign.

- Off, or for a campaign id that does not exist: a short page saying the link opens in a
  Cardano wallet app. It carries nothing about the campaign, and the response is the same
  in both cases.
- On: a form for the claim code and a button to connect a CIP-30 wallet extension, which
  supplies the address. There is no field for typing an address. The page posts to the
  address it was served from, so no cross-origin request is involved, and it carries no
  campaign data and no code.

The setting changes only this page. The `POST` to the same address accepts claims the same
way whether the setting is on or off.

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
| `codes:status`  | `GET /api/v1/campaigns/{campaign}/codes/{code}/status`, `GET /api/v1/campaigns/{campaign}` |
| `codes:claim`   | `POST /api/claim/v1/{campaign}` (see [Claiming on an attendee's behalf](#claiming-on-an-attendees-behalf)) |

### Create a code

```
POST /api/v1/campaigns/{campaign}/codes
```

| Field       | Required | Description                                                        |
|-------------|----------|----------------------------------------------------------------------|
| `reference` | yes      | The caller's own key for whoever this code is for, up to 191 characters from `A-Z a-z 0-9 . _ : -`. |
| `lovelace`  | yes      | The code's ADA payment, at least 1 ADA. It is stored as the higher of this and the exact minimum for the tokens, so a request for 1 ADA with a token creates a code at the minimum. |
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

Asking about a claim that has been sent but has no `transaction_hash` yet also queues a check
with the transaction backend. The response does not wait for it: it reports the claim as it
stands, and a later poll carries the hash once the check has found it. Checks are limited to
one per campaign every `CLAIM_CHECK_DEBOUNCE_SECONDS` (15 by default), so polling faster than
that does not confirm a claim sooner.

### Read a campaign

```
GET /api/v1/campaigns/{campaign}
```

Requires the `codes:status` ability, so a token that can already check a code's status can confirm which campaign it is pointed at without being reissued. A token only ever sees its owner's campaigns. Returns `200` with:

| Field                  | Description                                                           |
|------------------------|-------------------------------------------------------------------------|
| `id`                   | The campaign's id.                                                     |
| `name`                 | The campaign's name.                                                   |
| `network`              | `mainnet`, `preprod` or `preview`.                                     |
| `start_date`, `end_date` | The first and last day claims are accepted, as `YYYY-MM-DD`.         |
| `status`               | `active`, `upcoming`, `ended` or `draft`.                              |
| `claims_open`          | `true` only while `status` is `active`.                                |
| `claims_closed_reason` | `null` while claims are open, otherwise the same value as `status`.    |
| `funding_address`      | The campaign's wallet address, the one its owner sends the campaign's tokens and ADA to, or `null` when it has none. |
| `tokens`               | The distinct `{policy_id, token_id, quantity}` combinations on the campaign's codes, quantity being the amount one claim pays. Empty when no code carries a token. |

A campaign that does not exist and one that belongs to another account answer identically: `404` with the JSON body `{"message": "...", "code": "campaign_not_found"}`. The `code` field tells that answer apart from a host that has no such route, which returns a plain `404` without it.

The endpoints are rate-limited per token. Create is counted separately from status and campaign reads, which share one limit, so
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
