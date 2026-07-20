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

### Claim deep link

QR codes encode a `web+cardano://claim/v1?...` URI alongside the campaign claim URL so
compatible wallets/handlers can launch the claim flow directly.

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

> The proxy API and API tokens are **SaaS-only**. The self-hosted edition consumes them
> (as a client) but does not issue them. See [Editions](./editions).
