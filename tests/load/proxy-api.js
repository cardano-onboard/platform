/**
 * k6 Load Test — Proxy API Endpoints
 *
 * Tests Sanctum-authenticated proxy API under concurrency. This is the path
 * used by DIY self-hosted instances that route transactions through the
 * SaaS platform (TRANSACTION_BACKEND=proxy).
 *
 * Validates:
 *   - Quota middleware behavior under concurrent requests
 *   - Payment submission throughput (the hot path for claims)
 *   - Status check latency under load
 *   - Balance query performance
 *
 * Prerequisites:
 *   - Running app with TRANSACTION_BACKEND=proxy (or null for baseline)
 *   - A Sanctum API token for an authenticated user
 *   - Set environment variables:
 *       BASE_URL    - App URL (default: http://localhost:8080)
 *       API_TOKEN   - Sanctum bearer token
 *       NETWORK     - Network to test (default: preprod)
 *       PROFILE     - Resource profile: minimum|recommended|comfortable
 *
 * Usage:
 *   k6 run tests/load/proxy-api.js \
 *     -e BASE_URL=http://localhost:8080 \
 *     -e API_TOKEN=1|abc123... \
 *     -e PROFILE=recommended
 *
 * Testing both backend modes:
 *   1. Set TRANSACTION_BACKEND=null in .env, restart Docker, run this test
 *      → measures pure app + middleware performance
 *   2. Set TRANSACTION_BACKEND=proxy in .env, restart Docker, run this test
 *      → measures end-to-end including SaaS relay latency
 */

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.1.0/index.js';

const paymentDuration = new Trend('payment_submit_duration', true);
const statusDuration = new Trend('status_check_duration', true);
const balanceDuration = new Trend('balance_check_duration', true);
const bucketDuration = new Trend('bucket_create_duration', true);
const quotaHits = new Counter('quota_limit_hits');
const serverErrors = new Counter('server_errors');
const errorRate = new Rate('error_rate');

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const API_TOKEN = __ENV.API_TOKEN || 'REPLACE_WITH_TOKEN';
const NETWORK = __ENV.NETWORK || 'preprod';
const PROFILE = __ENV.PROFILE || 'recommended';

const authHeaders = {
    'Authorization': `Bearer ${API_TOKEN}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json',
};

const TEST_ADDRESS = 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3jcu5d8ps7zex2k2xt3uqxgjqnnj83ws8lhrn648jjxtwq2ytjc7';

const profiles = {
    minimum: {
        stages: [
            { duration: '15s', target: 3 },
            { duration: '1m', target: 3 },
            { duration: '15s', target: 8 },
            { duration: '1m', target: 8 },
            { duration: '15s', target: 0 },
        ],
        thresholds: {
            payment_submit_duration: ['p(95)<5000'],
            status_check_duration: ['p(95)<3000'],
            balance_check_duration: ['p(95)<3000'],
            error_rate: ['rate<0.3'],
            server_errors: ['count<5'],
        },
        description: '1 CPU / 512 MB — peak 8 concurrent API clients',
    },
    recommended: {
        stages: [
            { duration: '15s', target: 5 },
            { duration: '1m', target: 5 },
            { duration: '15s', target: 15 },
            { duration: '1m', target: 15 },
            { duration: '15s', target: 0 },
        ],
        thresholds: {
            payment_submit_duration: ['p(95)<3000'],
            status_check_duration: ['p(95)<1500'],
            balance_check_duration: ['p(95)<1500'],
            error_rate: ['rate<0.2'],
            server_errors: ['count<3'],
        },
        description: '2 CPU / 1 GB — peak 15 concurrent API clients',
    },
    comfortable: {
        stages: [
            { duration: '15s', target: 10 },
            { duration: '1m', target: 10 },
            { duration: '15s', target: 25 },
            { duration: '1m', target: 25 },
            { duration: '15s', target: 0 },
        ],
        thresholds: {
            payment_submit_duration: ['p(95)<2000'],
            status_check_duration: ['p(95)<1000'],
            balance_check_duration: ['p(95)<1000'],
            error_rate: ['rate<0.1'],
            server_errors: ['count<1'],
        },
        description: '4 CPU / 2 GB — peak 25 concurrent API clients',
    },
};

const profile = profiles[PROFILE] || profiles.recommended;

export const options = {
    stages: profile.stages,
    thresholds: profile.thresholds,
};

export default function () {
    const campaignId = `loadtest-${__VU}-${Date.now()}`;

    // Balance check (GET — lightweight, unmetered)
    group('balance', function () {
        const res = http.get(
            `${BASE_URL}/api/v1/proxy/balance?network=${NETWORK}&address=${TEST_ADDRESS}`,
            { headers: authHeaders }
        );

        balanceDuration.add(res.timings.duration);

        if (res.status >= 500) serverErrors.add(1);

        const ok = check(res, {
            'balance responds': (r) => r.status === 200 || r.status === 401,
        });
        errorRate.add(!ok);
    });

    sleep(0.2);

    // Payment submission (POST — unmetered, the hot path for proxy claims)
    group('payment', function () {
        const payload = JSON.stringify({
            network: NETWORK,
            campaign_id: campaignId,
            recipients: [{
                address: TEST_ADDRESS,
                lovelace: 2000000,
            }],
        });

        const res = http.post(`${BASE_URL}/api/v1/proxy/payment`, payload, {
            headers: authHeaders,
        });

        paymentDuration.add(res.timings.duration);

        if (res.status >= 500) serverErrors.add(1);

        const ok = check(res, {
            'payment accepted or auth required': (r) => r.status < 500,
        });
        errorRate.add(!ok);
    });

    sleep(0.2);

    // Status check (GET — unmetered)
    group('status', function () {
        const purchaseId = `test-${__VU}-${__ITER}`;
        const res = http.get(
            `${BASE_URL}/api/v1/proxy/status/${purchaseId}?network=${NETWORK}`,
            { headers: authHeaders }
        );

        statusDuration.add(res.timings.duration);

        if (res.status >= 500) serverErrors.add(1);

        const ok = check(res, {
            'status responds': (r) => r.status < 500,
        });
        errorRate.add(!ok);
    });

    sleep(0.2);

    // Bucket creation (POST — quota-limited)
    // Only attempt every 10th iteration to stay within quota
    if (__ITER % 10 === 0) {
        group('bucket_create', function () {
            const payload = JSON.stringify({
                network: NETWORK,
                campaign_id: campaignId,
            });

            const res = http.post(`${BASE_URL}/api/v1/proxy/bucket`, payload, {
                headers: authHeaders,
            });

            bucketDuration.add(res.timings.duration);

            if (res.status === 429) quotaHits.add(1);
            if (res.status >= 500) serverErrors.add(1);

            check(res, {
                'bucket responds': (r) => r.status < 500,
            });
        });
    }

    sleep(Math.random() * 0.5 + 0.3);
}

export function handleSummary(data) {
    const summary = {
        timestamp: new Date().toISOString(),
        test: 'proxy-api',
        profile: PROFILE,
        profileDescription: profile.description,
        metrics: {
            payment_p95: data.metrics.payment_submit_duration?.values?.['p(95)'],
            status_p95: data.metrics.status_check_duration?.values?.['p(95)'],
            balance_p95: data.metrics.balance_check_duration?.values?.['p(95)'],
            bucket_p95: data.metrics.bucket_create_duration?.values?.['p(95)'],
            quota_hits: data.metrics.quota_limit_hits?.values?.count || 0,
            server_errors: data.metrics.server_errors?.values?.count || 0,
            http_reqs_total: data.metrics.http_reqs?.values?.count,
            http_reqs_per_sec: data.metrics.http_reqs?.values?.rate,
            error_rate: data.metrics.error_rate?.values?.rate,
            vus_max: data.metrics.vus_max?.values?.max,
        },
        thresholds_passed: !Object.values(data.metrics).some(m => m.thresholds && Object.values(m.thresholds).some(t => !t.ok)),
    };

    const verdict = summary.thresholds_passed
        ? `PASS — ${PROFILE} profile meets performance requirements`
        : `FAIL — ${PROFILE} profile does NOT meet performance requirements`;

    return {
        [`tests/load/results/proxy-api-${PROFILE}.json`]: JSON.stringify(summary, null, 2),
        stdout: textSummary(data, { indent: ' ', enableColors: true }) + `\n\n=== VERDICT: ${verdict} ===\n`,
    };
}
