/**
 * k6 Load Test — Claim API Endpoint (Full Pipeline)
 *
 * Tests: POST /api/claim/v1/{campaignId}
 *
 * Exercises the complete claim pipeline:
 *   Code lookup → date validation → per-wallet check → claim record creation
 *   → transaction backend submission (null/phyrhose/proxy)
 *
 * Uses real codes seeded by seed-loadtest.js so every request goes through
 * the full processing path, not just a 404 shortcut.
 *
 * Prerequisites:
 *   - Running app (Docker stack)
 *   - Data seeded via: k6 run tests/load/seed-loadtest.js
 *   - Set environment variables:
 *       BASE_URL      - App URL (default: http://localhost:8080)
 *       CAMPAIGN_ID   - Campaign ULID from seed script
 *       CODE_PREFIX   - Code prefix from seed (default: LT_{first 8 of campaign ID})
 *       CODE_COUNT    - Number of seeded codes (default: 500)
 *       PROFILE       - Resource profile: minimum|recommended|comfortable
 *
 * Usage:
 *   k6 run tests/load/claim-api.js \
 *     -e BASE_URL=http://localhost:8080 \
 *     -e CAMPAIGN_ID=01HQ... \
 *     -e PROFILE=minimum
 *
 * Backend modes:
 *   Set TRANSACTION_BACKEND in the app's .env before starting Docker:
 *   - null:     Fake backend — measures pure app performance
 *   - phyrhose: Direct Phyrhose — measures end-to-end with real txn backend (preprod)
 *   - proxy:    SaaS proxy — measures relay overhead through the SaaS platform
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.1.0/index.js';

// Custom metrics
const claimAccepted = new Counter('claim_accepted');       // 200/201/202 — claim processed
const claimRateLimited = new Counter('claim_rate_limited'); // 429 — throttled
const claimNotFound = new Counter('claim_not_found');       // code not found (data issue)
const claimServerError = new Counter('claim_server_error'); // 500+ — real problem
const claimDuration = new Trend('claim_duration', true);
const successRate = new Rate('success_rate');

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const CAMPAIGN_ID = __ENV.CAMPAIGN_ID || 'REPLACE_WITH_CAMPAIGN_ID';
const CODE_COUNT = parseInt(__ENV.CODE_COUNT || '500');
const PROFILE = __ENV.PROFILE || 'recommended';

// Derive code prefix from campaign ID (matches seed script pattern)
const CODE_PREFIX = __ENV.CODE_PREFIX || `LT_${CAMPAIGN_ID.substring(0, 8)}`;

// Valid preprod test addresses — use multiple to simulate different wallets
const TEST_ADDRESSES = [
    'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3jcu5d8ps7zex2k2xt3uqxgjqnnj83ws8lhrn648jjxtwq2ytjc7',
    'addr_test1qpq4dg3gy5cennsmr75pnkxfk2egg67gu6su9fn5a2k7cwkgz4v4acfxyqwnkfk5yjv5wavnyajnjyqfm964lrpkd7a0s94pm47',
    'addr_test1qr5sp7hcfagxypz67nzxfjgp6gu8jmsxjsqw7v0c8sc36gfk3emhf63z8g3k0kl2rsmhqjr4hs08rj7lqzdsz3p4qywhqa9f82f',
];

// Profile-specific load stages and thresholds
const profiles = {
    minimum: {
        stages: [
            { duration: '15s', target: 5 },
            { duration: '1m', target: 5 },
            { duration: '15s', target: 10 },
            { duration: '1m', target: 10 },
            { duration: '15s', target: 0 },
        ],
        thresholds: {
            claim_duration: ['p(95)<3000'],   // p95 under 3s for minimum hardware
            success_rate: ['rate>0.5'],        // at least 50% succeed (rest may be rate-limited)
            claim_server_error: ['count<5'],   // near-zero 500 errors
        },
        description: '1 CPU / 512 MB app / 512 MB MySQL — peak 10 VUs',
    },
    recommended: {
        stages: [
            { duration: '15s', target: 10 },
            { duration: '1m', target: 10 },
            { duration: '15s', target: 30 },
            { duration: '1m', target: 30 },
            { duration: '15s', target: 0 },
        ],
        thresholds: {
            claim_duration: ['p(95)<2000'],   // p95 under 2s
            success_rate: ['rate>0.6'],
            claim_server_error: ['count<3'],
        },
        description: '2 CPU / 1 GB app / 1 GB MySQL — peak 30 VUs',
    },
    comfortable: {
        stages: [
            { duration: '15s', target: 20 },
            { duration: '1m', target: 20 },
            { duration: '15s', target: 50 },
            { duration: '1m', target: 50 },
            { duration: '15s', target: 0 },
        ],
        thresholds: {
            claim_duration: ['p(95)<1000'],   // p95 under 1s
            success_rate: ['rate>0.7'],
            claim_server_error: ['count<1'],
        },
        description: '4 CPU / 2 GB app / 2 GB MySQL — peak 50 VUs',
    },
};

const profile = profiles[PROFILE] || profiles.recommended;

export const options = {
    stages: profile.stages,
    thresholds: profile.thresholds,
};

export default function () {
    // Pick a real seeded code — distribute across VUs and iterations
    const codeIndex = (__VU * 100 + __ITER) % CODE_COUNT;
    const code = `${CODE_PREFIX}_${String(codeIndex).padStart(5, '0')}`;

    // Pick a wallet address to simulate different claimers
    const address = TEST_ADDRESSES[__VU % TEST_ADDRESSES.length];

    const url = `${BASE_URL}/api/claim/v1/${CAMPAIGN_ID}`;

    const payload = JSON.stringify({
        code: code,
        address: address,
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };

    const res = http.post(url, payload, params);

    claimDuration.add(res.timings.duration);

    // Parse response
    let body = {};
    try {
        body = JSON.parse(res.body);
    } catch (e) {
        // Non-JSON response
    }

    // Categorize response
    if (res.status === 429) {
        claimRateLimited.add(1);
        successRate.add(true); // rate limiting working correctly is a success
    } else if (res.status >= 500) {
        claimServerError.add(1);
        successRate.add(false);
    } else if (body.status === 'notfound') {
        claimNotFound.add(1);
        successRate.add(false);
    } else {
        // Any 2xx or handled error (already claimed, etc.) means the pipeline worked
        claimAccepted.add(1);
        successRate.add(true);
    }

    check(res, {
        'no server errors': (r) => r.status < 500,
        'response has body': (r) => r.body && r.body.length > 0,
    });

    // Simulate realistic client pause between claims
    sleep(Math.random() * 0.3 + 0.1);
}

export function handleSummary(data) {
    const summary = {
        timestamp: new Date().toISOString(),
        test: 'claim-api',
        profile: PROFILE,
        profileDescription: profile.description,
        campaignId: CAMPAIGN_ID,
        metrics: {
            duration_p50: data.metrics.claim_duration?.values?.['p(50)'],
            duration_p95: data.metrics.claim_duration?.values?.['p(95)'],
            duration_p99: data.metrics.claim_duration?.values?.['p(99)'],
            duration_avg: data.metrics.claim_duration?.values?.avg,
            duration_max: data.metrics.claim_duration?.values?.max,
            http_reqs_total: data.metrics.http_reqs?.values?.count,
            http_reqs_per_sec: data.metrics.http_reqs?.values?.rate,
            claims_accepted: data.metrics.claim_accepted?.values?.count || 0,
            claims_rate_limited: data.metrics.claim_rate_limited?.values?.count || 0,
            claims_not_found: data.metrics.claim_not_found?.values?.count || 0,
            claims_server_error: data.metrics.claim_server_error?.values?.count || 0,
            success_rate: data.metrics.success_rate?.values?.rate,
            vus_max: data.metrics.vus_max?.values?.max,
        },
        thresholds_passed: !Object.values(data.metrics).some(m => m.thresholds && Object.values(m.thresholds).some(t => !t.ok)),
    };

    const verdict = summary.thresholds_passed
        ? `PASS — ${PROFILE} profile meets performance requirements`
        : `FAIL — ${PROFILE} profile does NOT meet performance requirements`;

    return {
        [`tests/load/results/claim-api-${PROFILE}.json`]: JSON.stringify(summary, null, 2),
        stdout: textSummary(data, { indent: ' ', enableColors: true }) + `\n\n=== VERDICT: ${verdict} ===\n`,
    };
}
