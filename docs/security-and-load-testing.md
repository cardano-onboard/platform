# Security Audit & Load Testing Report

## Assessment Details

| Field | Value |
|-------|-------|
| **Date** | 2026-04-05 (load + ZAP); 2026-06-09 (cross-user authorization additions) |
| **Version** | v1.1.0-beta (milestone-2 branch, pre-merge to staging) |
| **Assessed By** | Onboard.Ninja Development Team |
| **Target (SaaS)** | https://beta.onbd.io |
| **Target (Self-Hosted)** | Docker stack (localhost:8081) |
| **Tools** | PHPUnit 11, OWASP ZAP 2.16, k6 (Grafana) |

---

## 1. Automated Security Testing (PHPUnit)

**Test file:** `tests/Feature/SecurityTest.php` — 24 tests, 42 assertions

| Category | Tests | Status |
|----------|-------|--------|
| Security Headers | X-Content-Type-Options, X-Frame-Options, Referrer-Policy | PASS |
| XSS Prevention | Campaign name, description, txn_msg `strip_tags()` | PASS |
| Authentication Bypass | Dashboard, campaign creation, profile require auth | PASS |
| Authorization | Cross-user campaign view/update/delete forbidden (403) | PASS |
| Mass Assignment | `user_id` override ignored on campaign creation | PASS |
| Claim API Validation | Missing code, invalid address, invalid Bech32 charset, nonexistent campaign | PASS |
| SQL Injection | Campaign name injection, claim code injection — parameterized queries | PASS |
| Proxy API Auth | Unauthenticated requests rejected (401), invalid tokens rejected (401) | PASS |
| Input Validation | Oversized name (>255), invalid network, end date before start | PASS |
| Sensitive Data | Wallet keys (`key`, `skey`, `vkey`) hidden from JSON serialization | PASS |

**Run command:** `php artisan test tests/Feature/SecurityTest.php`

---

## 2. OWASP ZAP Baseline Scan

**Tool:** OWASP ZAP 2.16 (Docker: `ghcr.io/zaproxy/zaproxy:stable`)
**Scan type:** Baseline (passive scanning)
**Target:** http://localhost:8081 (Docker stack, null backend)

### Findings

| Risk | Alert | Instances | Status | Notes |
|------|-------|-----------|--------|-------|
| Medium | Content Security Policy (CSP) Header Not Set | 2 | Open | No CSP header configured. Recommend adding in M3. |
| Medium | Multiple X-Frame-Options Header Entries | 1 | Accepted | Set in both SecurityHeaders middleware and nginx config. Browsers use the most restrictive value (DENY). Will consolidate to middleware-only in M3. |
| Medium | Sub Resource Integrity Attribute Missing | 2 | Accepted | Vite-bundled assets served from same origin. SRI is recommended for CDN-hosted scripts; lower risk for self-hosted assets. |
| Low | Cookie No HttpOnly Flag | 1 | Open | XSRF-TOKEN cookie needs to be readable by JavaScript (Inertia/Axios). This is by design for CSRF protection. |
| Low | Cross-Origin-Embedder-Policy Header Missing | 1 | Open | Add COEP header in M3 security hardening. |
| Low | Cross-Origin-Opener-Policy Header Missing | 1 | Open | Add COOP header in M3 security hardening. |
| Low | Cross-Origin-Resource-Policy Header Missing | 5 | Open | Add CORP header in M3 security hardening. |
| Low | Permissions-Policy Header Not Set | 5 | Open | Add Permissions-Policy header in M3 to restrict browser features. |
| Low | Server Leaks Version via "Server" Header | 5 | Open | nginx exposes version. Add `server_tokens off;` to nginx config in M3. |
| Low | X-Content-Type-Options Missing (static assets) | 5 | Accepted | SecurityHeaders middleware covers dynamic routes. Static assets served directly by nginx bypass middleware. Will add to nginx config in M3. |
| Info | Timestamp Disclosure - Unix | 5 | Accepted | Unix timestamps in JSON responses (expected for date fields). |
| Info | Suspicious Comments | 1 | Accepted | Standard code comments in bundled JavaScript. |
| Info | Modern Web Application | 1 | Accepted | Detection of SPA framework (expected). |
| Info | Storable and Cacheable Content | 5 | Accepted | Public pages (login, welcome) are cacheable by design. |

### Summary
- **Total alerts:** 16
- **High risk:** 0
- **Medium risk:** 3 (CSP, duplicate X-Frame-Options, SRI)
- **Low risk:** 7 (cookies, CORS headers, server version, static asset headers)
- **Informational:** 6

### Remediation Plan (M3)
1. Add Content Security Policy header
2. Consolidate X-Frame-Options to middleware only (remove from nginx)
3. Add `server_tokens off` to nginx config
4. Add COEP, COOP, CORP, Permissions-Policy headers to SecurityHeaders middleware
5. Add X-Content-Type-Options to nginx for static assets

---

## 3. Manual Security Audit

| # | Check | Result | Notes |
|---|-------|--------|-------|
| 1 | **CSRF** — X-XSRF-TOKEN enforced on state-changing endpoints | PASS | Laravel/Inertia handles CSRF via encrypted cookie + X-XSRF-TOKEN header |
| 2 | **XSS** — Script injection in campaign name/description | PASS | `strip_tags()` applied on store and update in CampaignController |
| 3 | **SQL Injection** — Injection in claim code, campaign fields | PASS | Eloquent parameterized queries throughout. Verified with `' OR 1=1; --` payloads |
| 4 | **Auth Bypass** — Accessing `/campaigns/{other_user_id}` | PASS | CampaignPolicy + `authorizeResource()` in controller constructor |
| 5 | **Rate Limiting** — Claim endpoint throttling | PASS | 60 req/min per IP, 120 req/min per campaign. Verified under load — 4222 rate-limited responses at minimum profile |
| 6 | **Security Headers** — X-Content-Type-Options, X-Frame-Options, HSTS | PASS | SecurityHeaders middleware sets headers on all dynamic responses |
| 7 | **Sanctum Tokens** — Invalid/expired tokens | PASS | Returns 401 Unauthorized. Verified in SecurityTest.php |
| 8 | **Mass Assignment** — Extra fields on campaign create | PASS | Controller uses validated data + `Auth::user()->id`. Injected `user_id` ignored |
| 9 | **File Upload** — Size and type limits | PASS | `config/cardano.php`: max_file_size (10MB), max_codes (10000) |
| 10 | **Wallet Key Exposure** — keys hidden from API | PASS | `$hidden` array on Wallet model excludes `key`, `skey`, `vkey` |
| 11 | **Session Fixation** — Session regenerated on login | PASS | Laravel Breeze calls `$request->session()->regenerate()` |
| 12 | **Password Hashing** — bcrypt with configurable rounds | PASS | Laravel default bcrypt, rounds=4 in testing, default in production |

---

## 4. Load/Stress Testing Results

**Tool:** k6 v0.56 (Grafana)
**Backend:** `TRANSACTION_BACKEND=null` (isolates app performance from external API latency)
**Test data:** 500 pre-seeded claim codes per campaign
**Scripts:** `tests/load/claim-api.js`, `tests/load/dashboard.js`

### Claim API — `POST /api/claim/v1/{campaign}`

The claim API is the only publicly exposed unauthenticated endpoint and the most likely to experience traffic spikes during airdrop events.

| Metric | Minimum (1.5 GB) | Recommended (2.5 GB) | Comfortable (4.5 GB) |
|--------|-------------------|----------------------|----------------------|
| Peak VUs | 10 | 30 | 50 |
| Requests/sec | 26.6 | 72.5 | 126.4 |
| p95 Latency | 4.9 ms | 4.2 ms | 4.0 ms |
| Avg Latency | 3.8 ms | 3.5 ms | 3.8 ms |
| Max Latency | 175 ms | 187 ms | 297 ms |
| Claims Accepted | 183 | 183 | 181 |
| Rate Limited (429) | 4,222 | 11,621 | 20,368 |
| Server Errors (5xx) | 0 | 0 | 0 |
| Success Rate | 100% | 100% | 100% |
| **Verdict** | **PASS** | **PASS** | **PASS** |

**Notes:** Rate limiting is working correctly — the majority of requests are throttled (60/min per IP). The ~183 accepted claims represent the rate limit window across the test duration. Zero server errors across all profiles.

### Dashboard — Login + Page Views (Authenticated)

Tests the Inertia rendering pipeline under concurrent authenticated sessions.

| Metric | Minimum (1.5 GB) | Recommended (2.5 GB) | Comfortable (4.5 GB) |
|--------|-------------------|----------------------|----------------------|
| Peak VUs | 8 | 15 | 30 |
| Requests/sec | 6.6 | 12.2 | 23.7 |
| Login p95 | 51 ms | 53 ms | 56 ms |
| Dashboard p95 | 14 ms | 16 ms | 22 ms |
| Campaign View p95 | 37 ms | 36 ms | 38 ms |
| Error Rate | 0% | 0% | 0% |
| **Verdict** | **PASS** | **PASS** | **PASS** |

### Docker Resource Usage (Post-Test Peak)

| Service | Minimum (512 MB) | Recommended (1 GB) | Comfortable (2 GB) |
|---------|-------------------|---------------------|---------------------|
| **App** | 150 MB (29%) | 155 MB (15%) | 162 MB (8%) |
| **MySQL** | 383 MB (75%) | 378 MB (37%) | 373 MB (18%) |
| **Redis** | 5 MB (4%) | 4 MB (2%) | 4 MB (1%) |

---

## 5. Hardware Recommendations

### Minimum Requirements (Self-Hosted)
- **CPU:** 2 cores (1 for app, 0.5 for MySQL, 0.25 for Redis)
- **RAM:** 1.5 GB total (512 MB app, 512 MB MySQL, 128 MB Redis)
- **Disk:** 10 GB (application + database)
- **Expected capacity:** ~10 concurrent claimers, ~8 admin sessions, ~27 claim req/sec
- **Warning:** MySQL uses 75% of available memory at this tier. Not recommended for campaigns with >1000 codes or sustained high traffic.

### Recommended Specifications
- **CPU:** 4 cores (2 for app, 1 for MySQL, 0.5 for Redis)
- **RAM:** 2.5 GB total (1 GB app, 1 GB MySQL, 256 MB Redis)
- **Disk:** 20 GB SSD
- **Expected capacity:** ~30 concurrent claimers, ~15 admin sessions, ~73 claim req/sec
- **Best for:** Most self-hosted deployments, events with up to several hundred attendees.

### Comfortable Specifications
- **CPU:** 7 cores (4 for app, 2 for MySQL, 0.5 for Redis)
- **RAM:** 4.5 GB total (2 GB app, 2 GB MySQL, 256 MB Redis)
- **Disk:** 40 GB SSD
- **Expected capacity:** ~50 concurrent claimers, ~30 admin sessions, ~126 claim req/sec
- **Best for:** Large events, multiple concurrent campaigns, high-traffic airdrop drops.

### PHP-FPM Tuning

The `docker/php/www.conf` sets `pm.max_children = 20`. Each PHP-FPM worker uses ~30-50 MB RAM.

| App RAM | Recommended pm.max_children | Concurrent Capacity |
|---------|----------------------------|---------------------|
| 512 MB | 8-10 | ~10 requests |
| 1 GB | 15-20 | ~20 requests |
| 2 GB | 30-40 | ~40 requests |

If load tests show `502 Bad Gateway` errors, reduce `pm.max_children` or increase app memory.

---

## 6. Remediation Summary

Items identified during security scanning, prioritized for future milestones:

| # | Finding | Severity | Status | Target |
|---|---------|----------|--------|--------|
| 1 | Content Security Policy header not set | Medium | Open | M3 |
| 2 | Duplicate X-Frame-Options (middleware + nginx) | Medium | Accepted | M3 |
| 3 | Sub Resource Integrity missing on bundled assets | Medium | Accepted | M3 |
| 4 | nginx leaks server version | Low | Open | M3 |
| 5 | Missing COEP/COOP/CORP headers | Low | Open | M3 |
| 6 | Missing Permissions-Policy header | Low | Open | M3 |
| 7 | X-Content-Type-Options missing on static assets | Low | Open | M3 |
| 8 | XSRF-TOKEN cookie not HttpOnly | Low | Accepted | By design (Inertia/Axios requires JS access) |

---

## 7. Test Execution Summary

| Test Suite | Count | Pass | Fail | Command |
|------------|-------|------|------|---------|
| PHPUnit (backend + security) | 136 | 136 | 0 | `php artisan test` |
| Vitest (frontend components) | 38 | 38 | 0 | `npm test` |
| Dusk (E2E browser) | 20 | 20 | 0 | `php artisan dusk` |
| k6 Claim API (3 profiles) | 3 | 3 | 0 | `./tests/load/run-loadtests.sh` |
| k6 Dashboard (3 profiles) | 3 | 3 | 0 | `./tests/load/run-loadtests.sh` |
| OWASP ZAP baseline | 1 | — | — | `./tests/security/run-security-scan.sh` |
| **Total** | **201** | **200** | **0** | |
