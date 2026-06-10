/**
 * k6 Setup Script — Seed Load Test Data
 *
 * Creates a campaign with N codes via the application API so load tests
 * exercise the full claim pipeline (DB lookup → validation → backend call → claim record).
 *
 * This script runs once before the main load tests. It authenticates as the
 * test user, creates a campaign, and generates codes.
 *
 * Prerequisites:
 *   - Running app with a registered user
 *   - TRANSACTION_BACKEND set to null, phyrhose, or proxy
 *
 * Usage:
 *   k6 run tests/load/seed-loadtest.js \
 *     -e BASE_URL=http://localhost:8080 \
 *     -e TEST_EMAIL=admin@onboard.ninja \
 *     -e TEST_PASSWORD=password \
 *     -e CODE_COUNT=1000
 *
 * Output:
 *   Prints the CAMPAIGN_ID to stdout for use in subsequent tests.
 *   Also writes tests/load/results/seed-data.json with all generated codes.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
// Default credentials match .env.docker AdminSeeder config
const TEST_EMAIL = __ENV.TEST_EMAIL || 'admin@example.com';
const TEST_PASSWORD = __ENV.TEST_PASSWORD || 'changeme123';
const CODE_COUNT = parseInt(__ENV.CODE_COUNT || '500');

export const options = {
    vus: 1,
    iterations: 1,
};

export default function () {
    const jar = http.cookieJar();

    // Step 1: Get CSRF token by visiting login page
    const loginPage = http.get(`${BASE_URL}/login`);
    const cookies = jar.cookiesForURL(BASE_URL);
    const xsrfToken = cookies['XSRF-TOKEN'] ? decodeURIComponent(cookies['XSRF-TOKEN'][0]) : '';

    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-XSRF-TOKEN': xsrfToken,
        'X-Inertia': 'true',
        'X-Inertia-Version': '',
    };

    // Step 2: Login
    const loginRes = http.post(`${BASE_URL}/login`, JSON.stringify({
        email: TEST_EMAIL,
        password: TEST_PASSWORD,
    }), { headers });

    const loginOk = check(loginRes, {
        'login succeeded': (r) => r.status === 200 || r.status === 302 || r.status === 303 || r.status === 409,
    });

    if (!loginOk) {
        console.error(`Login failed: ${loginRes.status} ${loginRes.body}`);
        return;
    }

    // Refresh CSRF after login
    const dashPage = http.get(`${BASE_URL}/dashboard`);
    const newCookies = jar.cookiesForURL(BASE_URL);
    const newXsrf = newCookies['XSRF-TOKEN'] ? decodeURIComponent(newCookies['XSRF-TOKEN'][0]) : '';
    headers['X-XSRF-TOKEN'] = newXsrf;

    // Step 3: Create a load test campaign
    const today = new Date();
    const endDate = new Date(today.getTime() + 30 * 24 * 60 * 60 * 1000); // 30 days from now

    const campaignRes = http.post(`${BASE_URL}/campaigns`, JSON.stringify({
        name: `Load Test ${today.toISOString()}`,
        description: 'Automated load test campaign — safe to delete',
        start_date: today.toISOString().split('T')[0],
        end_date: endDate.toISOString().split('T')[0],
        network: 'preprod',
        one_per_wallet: 0,
    }), { headers, redirects: 0 });

    // Extract campaign ID from redirect location
    const location = campaignRes.headers['Location'] || campaignRes.headers['location'] || '';
    const campaignIdMatch = location.match(/campaigns\/([A-Za-z0-9]+)/);

    if (!campaignIdMatch) {
        console.error(`Campaign creation failed: ${campaignRes.status}`);
        console.error(`Location: ${location}`);
        console.error(`Body: ${campaignRes.body?.substring(0, 500)}`);
        return;
    }

    const campaignId = campaignIdMatch[1];
    console.log(`Created campaign: ${campaignId}`);

    // Step 4: Generate codes in batches
    // Refresh CSRF
    const campPage = http.get(`${BASE_URL}/campaigns/${campaignId}`);
    const campCookies = jar.cookiesForURL(BASE_URL);
    headers['X-XSRF-TOKEN'] = campCookies['XSRF-TOKEN'] ? decodeURIComponent(campCookies['XSRF-TOKEN'][0]) : '';

    const codes = [];
    for (let i = 0; i < CODE_COUNT; i++) {
        const code = `LT_${campaignId.substring(0, 8)}_${String(i).padStart(5, '0')}`;
        codes.push(code);

        const codeRes = http.post(`${BASE_URL}/codes`, JSON.stringify({
            campaign_id: campaignId,
            code: code,
            lovelace: 2000000,
            uses: 100,       // Allow many claims per code for load testing
            perWallet: 0,    // No per-wallet limit
            tokens: [],
        }), { headers, redirects: 0 });

        if (i % 50 === 0) {
            console.log(`  Created ${i + 1}/${CODE_COUNT} codes...`);
            // Refresh CSRF periodically
            http.get(`${BASE_URL}/campaigns/${campaignId}`);
            const refreshCookies = jar.cookiesForURL(BASE_URL);
            headers['X-XSRF-TOKEN'] = refreshCookies['XSRF-TOKEN'] ? decodeURIComponent(refreshCookies['XSRF-TOKEN'][0]) : '';
            sleep(0.1);
        }
    }

    console.log(`\n=== LOAD TEST DATA SEEDED ===`);
    console.log(`Campaign ID: ${campaignId}`);
    console.log(`Codes created: ${codes.length}`);
    console.log(`\nUse this campaign ID for load tests:`);
    console.log(`  k6 run tests/load/claim-api.js -e CAMPAIGN_ID=${campaignId}\n`);

    // Write seed data to results file for use by other scripts
    return {
        campaignId,
        codes,
    };
}

export function handleSummary(data) {
    // Extract seed data from the default function's return value if available
    return {
        stdout: '\n--- Seed script complete. See output above for CAMPAIGN_ID. ---\n',
    };
}
