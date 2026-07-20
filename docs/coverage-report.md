# Code Coverage & Testing Report

**Date:** 2026-06-29 (coverage re-measured on staging)
**Version:** v1.1.0-beta (staging)


## Test Suite Summary

| Suite                                                 | Tests     | Assertions | Status            | Command                         |
|-------------------------------------------------------|-----------|------------|-------------------|---------------------------------|
| PHPUnit (backend + security + multi-claim regression) | 164       | 332        | All passing       | `php artisan test`              |
| Vitest (frontend components)                          | 40        | -          | All passing       | `npm test`                      |
| Dusk (E2E browser)                                    | 20        | 36         | All passing       | `php artisan dusk`              |
| k6 (load/stress)                                      | 3 scripts | -          | All profiles pass | `./tests/load/run-loadtests.sh` |
| **Total**                                             | **224+**  | **368+**   | **All passing**   |                                 |

**Changes since 2026-04-07 report:**

- +7 tests in `ClaimMultiUseTest` (multi-claim regression suite ported from
  staging hotfix)
- +4 tests in `SecurityTest` (cross-user authorization gaps: code create,
  refund, check-claims, API token revoke)

## PHP Code Coverage (PCOV)

**Overall: 67.3%** (CI threshold: 50%)

### Controllers

| Class                   | Coverage | Notes                                               |
|-------------------------|----------|-----------------------------------------------------|
| CampaignController      | 61.8%    | Campaign CRUD, check claims, refund, QR download    |
| CodeController          | 55.8%    | Claim logic heavily branched; needs more test paths |
| PhyrhoseProxyController | 100.0%   | All 5 proxy endpoints covered                       |
| ProfileController       | 77.8%    | Profile CRUD, token management                      |
| JobsController          | 0.0%     | Minimal controller, dispatches jobs                 |
| Controller (base)       | 100.0%   | -                                                   |

### Middleware

| Class                 | Coverage |
|-----------------------|----------|
| CheckProxyQuota       | 100.0%   |
| HandleInertiaRequests | 100.0%   |
| LogProxyUsage         | 100.0%   |
| SecurityHeaders       | 85.7%    |

### Models

| Class      | Coverage | Notes                                |
|------------|----------|--------------------------------------|
| Campaign   | 86.7%    | Includes status accessor             |
| Code       | 100.0%   | -                                    |
| Claim      | 50.0%    | Relationship and accessor methods    |
| Reward     | 100.0%   | -                                    |
| Wallet     | 10.5%    | Balance queries use external APIs    |
| User       | 0.0%     | Laravel default, relationships only  |
| ProxyUsage | 0.0%     | Simple model, created via middleware |

### Jobs

| Class                 | Coverage |
|-----------------------|----------|
| ProcessClaims         | 92.0%    |
| CheckClaims           | 72.7%    |
| ProcessUploadedCodes  | 81.8%    |
| CreateCampaignBucket  | 69.2%    |
| ManuallyProcessClaims | 0.0%     |

### Services

| Class           | Coverage |
|-----------------|----------|
| NullBackend     | 100.0%   |
| ProxyBackend    | 100.0%   |
| PhyrhoseBackend | 57.7%    |

### Other

| Class                              | Coverage | Notes                                  |
|------------------------------------|----------|----------------------------------------|
| TransactionBackend (contract)      | 100.0%   | -                                      |
| ClaimException                     | 100.0%   | -                                      |
| CardanoAddress (rule)              | 53.3%    | -                                      |
| AppServiceProvider                 | 80.4%    | -                                      |
| CampaignPolicy                     | 62.5%    | -                                      |
| UserPolicy                         | 0.0%     | Added post-report; not yet exercised   |
| LoginRequest                       | 65.2%    | -                                      |
| ProfileUpdateRequest               | 100.0%   | -                                      |
| TokenCollection (resource)         | 57.1%    | Added post-report; API token listing   |
| ProvisionOrphanedCampaigns (cmd)   | 0.0%     | Added post-report; ops command, no test |

### Excluded from Coverage

The following are excluded from coverage metrics (unchanged Laravel/Breeze
boilerplate):

- `app/Http/Controllers/Auth/*`: Breeze auth controllers
- `app/Providers/BroadcastServiceProvider.php`: unused default provider


## Frontend Coverage (Vitest)

**40 tests across 4 files**

| Test File              | Tests | What's Covered                                                                           |
|------------------------|-------|------------------------------------------------------------------------------------------|
| Dashboard.test.js      | 10    | Campaign list, empty state, create dialog, delete confirm, status badges, column headers |
| Welcome.test.js        | 11    | Landing page, auth states, TEST MODE banner, beta banner, dark mode, footer links        |
| CampaignCreate.test.js | 8     | Form fields, network options, default values, validation errors                          |
| CampaignShow.test.js   | 11    | Campaign details, wallet address, claim URL, provisioning, backend mismatch, data table  |

**Test environment:** jsdom with real Vuetify rendering. Only MeshJS (WASM) and
Inertia navigation are mocked.


## E2E Browser Coverage (Laravel Dusk)

**20 tests across 5 files**, running against the Docker stack with Selenium
Chrome.

| Test File            | Tests | What's Covered                                                                 |
|----------------------|-------|--------------------------------------------------------------------------------|
| AuthTest.php         | 5     | Login page, login flow, wrong password, logout, auth redirect                  |
| CampaignTest.php     | 6     | Empty dashboard, create campaign, view details, delete, stats, cross-user 403  |
| ClaimFlowTest.php    | 4     | Codes table display, claim URL, API validation (missing code, invalid address) |
| ProfileTest.php      | 4     | Profile page, user email, delete section, API tokens section                   |
| RegistrationTest.php | 1     | Registration page loads                                                        |

**Runs in CI:** Yes, parallel job gating both staging and production deploys.


## API Endpoint Coverage Matrix

| Endpoint                            | Method | Auth    | PHPUnit            | Dusk      | k6        |
|-------------------------------------|--------|---------|--------------------|-----------|-----------|
| `GET /`                             | GET    | No      | Feature            | -         | -         |
| `GET /login`                        | GET    | No      | Auth               | Auth      | Dashboard |
| `POST /login`                       | POST   | No      | Auth               | Auth      | Dashboard |
| `POST /logout`                      | POST   | Yes     | Auth               | Auth      | -         |
| `GET /register`                     | GET    | No      | Auth               | Reg       | -         |
| `POST /register`                    | POST   | No      | Auth               | -         | -         |
| `GET /dashboard`                    | GET    | Yes     | Feature            | Campaign  | Dashboard |
| `GET /profile`                      | GET    | Yes     | Profile            | Profile   | -         |
| `PATCH /profile`                    | PATCH  | Yes     | Profile            | -         | -         |
| `DELETE /profile`                   | DELETE | Yes     | Profile            | -         | -         |
| `POST /profile/tokens`              | POST   | Yes     | Profile            | -         | -         |
| `GET /campaigns`                    | GET    | Yes     | Campaign           | -         | -         |
| `POST /campaigns`                   | POST   | Yes     | Campaign           | Campaign  | -         |
| `GET /campaigns/{id}`               | GET    | Yes     | Campaign           | Campaign  | Dashboard |
| `PUT /campaigns/{id}`               | PUT    | Yes     | Campaign           | -         | -         |
| `DELETE /campaigns/{id}`            | DELETE | Yes     | Campaign           | Campaign  | -         |
| `POST /campaigns/{id}/check-claims` | POST   | Yes     | Campaign           | -         | -         |
| `POST /campaigns/{id}/refund`       | POST   | Yes     | Campaign           | -         | -         |
| `GET /campaigns/{id}/download-qr`   | GET    | Yes     | QrDownload         | -         | -         |
| `POST /codes`                       | POST   | Yes     | Code, Bulk         | -         | -         |
| `POST /api/claim/v1/{campaign}`     | POST   | No      | ClaimApi, Security | ClaimFlow | Claim     |
| `POST /api/v1/proxy/bucket`         | POST   | Sanctum | Proxy, Security    | -         | Proxy     |
| `POST /api/v1/proxy/payment`        | POST   | Sanctum | Proxy              | -         | Proxy     |
| `GET /api/v1/proxy/status/{id}`     | GET    | Sanctum | Proxy              | -         | Proxy     |
| `POST /api/v1/proxy/refund`         | POST   | Sanctum | Proxy              | -         | -         |
| `GET /api/v1/proxy/balance`         | GET    | Sanctum | Proxy, Security    | -         | Proxy     |

**Coverage:** 26/26 endpoints have at least one test. The claim API endpoint has
the highest coverage (PHPUnit + Security + Dusk + k6).


## Improvement Targets (M3)

| Area                      | Current        | Target        | Issue                                                      |
|---------------------------|----------------|---------------|------------------------------------------------------------|
| PHP overall coverage      | 67.3%          | 70%           | [#7](https://github.com/cardano-onboard/platform/issues/7) |
| CodeController coverage   | 55.8%          | 60%+          | [#7](https://github.com/cardano-onboard/platform/issues/7) |
| Vitest coverage reporting | Not configured | 60% threshold | [#8](https://github.com/cardano-onboard/platform/issues/8) |
| Dusk code creation tests  | Not covered    | Covered       | [#9](https://github.com/cardano-onboard/platform/issues/9) |
