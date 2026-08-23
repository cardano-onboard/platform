# Load Testing

Load tests for Onboard.Ninja using [k6](https://grafana.com/docs/k6/) to determine minimum and recommended hardware specifications for self-hosted (DIY) deployments.

## Overview

The load tests exercise the **full application pipeline** — not just endpoint availability. Claims go through code lookup, date validation, per-wallet checks, claim record creation, and transaction backend submission. This ensures the results reflect real-world performance.

## Prerequisites

- [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) installed
- Docker and Docker Compose
- Application `.env` configured

## Test Scripts

| Script | What It Tests | Auth |
|--------|---------------|------|
| `seed-loadtest.js` | Creates a campaign + 500 codes for realistic claim testing | Session |
| `claim-api.js` | `POST /api/claim/v1/{campaign}` — full claim pipeline with real codes | None |
| `dashboard.js` | Login → Dashboard → Campaign view — Inertia rendering under load | Session |
| `proxy-api.js` | Proxy API (bucket, payment, status, balance) — SaaS relay path | Sanctum |

## Resource Profiles

Each profile defines resource limits AND pass/fail thresholds:

| Profile | App | MySQL | Redis | Total | Claim p95 | Dashboard p95 |
|---------|-----|-------|-------|-------|-----------|---------------|
| `minimum` | 1 CPU / 512 MB | 0.5 CPU / 512 MB | 0.25 CPU / 128 MB | ~1.5 GB | < 3s | < 3s |
| `recommended` | 2 CPU / 1 GB | 1 CPU / 1 GB | 0.5 CPU / 256 MB | ~2.5 GB | < 2s | < 2s |
| `comfortable` | 4 CPU / 2 GB | 2 CPU / 2 GB | 0.5 CPU / 256 MB | ~4.5 GB | < 1s | < 1.5s |

## Quick Start

Run all tests for a specific profile:

```bash
./tests/load/run-loadtests.sh minimum
```

The runner script will:
1. Start the Docker stack with resource limits
2. Seed a campaign with 500 codes
3. Run claim API, dashboard, and proxy API tests
4. Capture Docker resource usage before, during, and after
5. Report **PASS** or **FAIL** with a clear verdict

## Backend Modes

Self-hosted instances can connect to the transaction backend in two ways. Test both:

### Null Backend (Baseline)
Measures pure application performance without external API latency:
```bash
# Set TRANSACTION_BACKEND=null in .env, then:
./tests/load/run-loadtests.sh recommended --backend=null
```

### Direct Phyrhose (Preprod)
Measures end-to-end with real Phyrhose transaction backend:
```bash
# Set TRANSACTION_BACKEND=phyrhose and preprod credentials in .env, then:
./tests/load/run-loadtests.sh recommended --backend=phyrhose
```

### SaaS Proxy
Measures relay overhead through the SaaS platform:
```bash
# Set TRANSACTION_BACKEND=proxy in .env, then:
./tests/load/run-loadtests.sh recommended --backend=proxy --api-token=1|abc123...
```

## Running Individual Tests

```bash
# Seed data first
k6 run tests/load/seed-loadtest.js -e BASE_URL=http://localhost:8080

# Then run individual tests with the campaign ID from seed output
k6 run tests/load/claim-api.js -e CAMPAIGN_ID=01HQ... -e PROFILE=minimum
k6 run tests/load/dashboard.js -e CAMPAIGN_ID=01HQ... -e PROFILE=minimum
k6 run tests/load/proxy-api.js -e API_TOKEN=1|abc... -e PROFILE=minimum
```

## Results

Results are saved to `tests/load/results/` (git-ignored):

- `claim-api-{profile}.json` — structured metrics with pass/fail verdict
- `dashboard-{profile}.json` — structured metrics with pass/fail verdict
- `proxy-api-{profile}.json` — structured metrics with pass/fail verdict
- `docker-stats-{profile}-{pre|mid|post}.txt` — resource usage snapshots
- `*.log` — full k6 console output

## PHP-FPM Tuning

The `docker/php/www.conf` sets `pm.max_children = 20`. Each PHP-FPM worker uses ~30-50 MB RAM.

| App RAM | Safe max_children | Concurrent Capacity |
|---------|-------------------|---------------------|
| 512 MB | 8-10 | ~10 requests |
| 1 GB | 15-20 | ~20 requests |
| 2 GB | 30-40 | ~40 requests |

If load tests show `502 Bad Gateway` errors, reduce `pm.max_children` or increase app memory.
