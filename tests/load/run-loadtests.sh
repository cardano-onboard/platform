#!/usr/bin/env bash
set -euo pipefail

# Run k6 load tests against the Docker stack with resource constraints.
#
# This script:
#   1. Starts the Docker stack with the specified resource profile
#   2. Seeds test data (campaign + codes)
#   3. Runs all three load tests
#   4. Captures Docker resource usage
#   5. Reports pass/fail per test
#
# Prerequisites:
#   - k6 installed: https://grafana.com/docs/k6/latest/set-up/install-k6/
#   - Docker Compose available
#   - Application .env configured (TRANSACTION_BACKEND, DB creds, etc.)
#
# Usage:
#   ./tests/load/run-loadtests.sh <profile> [options]
#
# Profiles: minimum | recommended | comfortable
#
# Options:
#   --skip-seed         Skip data seeding (reuse existing CAMPAIGN_ID)
#   --campaign-id=ID    Use existing campaign instead of seeding
#   --api-token=TOKEN   Sanctum token for proxy API tests
#   --skip-proxy        Skip proxy API tests
#   --backend=null|phyrhose|proxy   Which backend mode to label results as

PROFILE="${1:-}"
SKIP_SEED=false
CAMPAIGN_ID=""
API_TOKEN=""
SKIP_PROXY=false
BACKEND_LABEL="null"
BASE_URL="${BASE_URL:-http://localhost:8081}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RESULTS_DIR="$SCRIPT_DIR/results"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Parse options
shift || true
for arg in "$@"; do
    case "$arg" in
        --skip-seed) SKIP_SEED=true ;;
        --campaign-id=*) CAMPAIGN_ID="${arg#*=}" ;;
        --api-token=*) API_TOKEN="${arg#*=}" ;;
        --skip-proxy) SKIP_PROXY=true ;;
        --backend=*) BACKEND_LABEL="${arg#*=}" ;;
    esac
done

if [[ -z "$PROFILE" ]]; then
    echo "Usage: $0 <profile> [options]"
    echo ""
    echo "Profiles: minimum | recommended | comfortable"
    echo ""
    echo "Options:"
    echo "  --skip-seed           Reuse existing test data"
    echo "  --campaign-id=ID     Use specific campaign"
    echo "  --api-token=TOKEN    Sanctum token for proxy API tests"
    echo "  --skip-proxy          Skip proxy API tests"
    echo "  --backend=null|phyrhose|proxy  Label for results"
    echo ""
    echo "Example:"
    echo "  $0 minimum"
    echo "  $0 recommended --backend=proxy --api-token=1|abc123"
    exit 1
fi

# Resource profiles
case "$PROFILE" in
    minimum)
        export APP_CPUS=1.0 APP_MEMORY=512M
        export MYSQL_CPUS=0.5 MYSQL_MEMORY=512M
        export REDIS_CPUS=0.25 REDIS_MEMORY=128M
        TOTAL="~1.5 GB"
        ;;
    recommended)
        export APP_CPUS=2.0 APP_MEMORY=1024M
        export MYSQL_CPUS=1.0 MYSQL_MEMORY=1024M
        export REDIS_CPUS=0.5 REDIS_MEMORY=256M
        TOTAL="~2.5 GB"
        ;;
    comfortable)
        export APP_CPUS=4.0 APP_MEMORY=2048M
        export MYSQL_CPUS=2.0 MYSQL_MEMORY=2048M
        export REDIS_CPUS=0.5 REDIS_MEMORY=256M
        TOTAL="~4.5 GB"
        ;;
    *)
        echo "Unknown profile: $PROFILE"
        exit 1
        ;;
esac

mkdir -p "$RESULTS_DIR"

echo "================================================================"
echo " LOAD TEST: $PROFILE profile ($TOTAL)"
echo " Backend: $BACKEND_LABEL"
echo " Target:  $BASE_URL"
echo "================================================================"
echo ""
echo "  App:   ${APP_CPUS} CPUs / ${APP_MEMORY} RAM"
echo "  MySQL: ${MYSQL_CPUS} CPUs / ${MYSQL_MEMORY} RAM"
echo "  Redis: ${REDIS_CPUS} CPUs / ${REDIS_MEMORY} RAM"
echo ""

# ── Docker Stack ──────────────────────────────────────────────────────────
echo "--- Starting Docker stack with $PROFILE resource limits ---"
cd "$REPO_ROOT"
docker compose -f docker-compose.prod.yml -f docker-compose.loadtest.yml up -d --build 2>&1 | tail -5

echo "Waiting for app to be ready..."
for i in $(seq 1 60); do
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL" 2>/dev/null || echo "000")
    if [[ "$STATUS" != "000" ]]; then
        echo "  App responding (HTTP $STATUS) after $((i * 3))s"
        break
    fi
    if [[ "$i" == "60" ]]; then
        echo "  ERROR: App did not become ready after 180s"
        echo "  Check: docker logs onboard_app"
        exit 1
    fi
    sleep 3
done
# Give the app a few more seconds to finish booting
sleep 5
echo ""

# ── Pre-test Resource Snapshot ────────────────────────────────────────────
echo "--- Pre-test resource usage ---"
docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" 2>/dev/null | tee "$RESULTS_DIR/docker-stats-${PROFILE}-pre.txt"
echo ""

# ── Seed Test Data ────────────────────────────────────────────────────────
if [[ "$SKIP_SEED" == "false" && -z "$CAMPAIGN_ID" ]]; then
    echo "--- Seeding test data (500 codes) ---"
    k6 run "$SCRIPT_DIR/seed-loadtest.js" \
        -e BASE_URL="$BASE_URL" \
        -e CODE_COUNT=500 \
        2>&1 | tee "$RESULTS_DIR/seed-${PROFILE}.log"

    # Extract campaign ID from seed output
    CAMPAIGN_ID=$(grep "Campaign ID:" "$RESULTS_DIR/seed-${PROFILE}.log" | tail -1 | awk '{print $NF}')

    if [[ -z "$CAMPAIGN_ID" ]]; then
        echo "ERROR: Failed to extract campaign ID from seed script"
        exit 1
    fi
    echo ""
fi

echo "Using campaign: $CAMPAIGN_ID"
echo ""

# ── Test 1: Claim API ────────────────────────────────────────────────────
PASS_COUNT=0
FAIL_COUNT=0

echo "================================================================"
echo " TEST 1/3: Claim API"
echo "================================================================"
if k6 run "$SCRIPT_DIR/claim-api.js" \
    -e BASE_URL="$BASE_URL" \
    -e CAMPAIGN_ID="$CAMPAIGN_ID" \
    -e PROFILE="$PROFILE" \
    -e CODE_COUNT=500 \
    2>&1 | tee "$RESULTS_DIR/claim-api-${PROFILE}-${BACKEND_LABEL}.log"; then
    PASS_COUNT=$((PASS_COUNT + 1))
else
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi
echo ""

# ── Mid-test Resource Snapshot ────────────────────────────────────────────
echo "--- Mid-test resource usage ---"
docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" 2>/dev/null | tee "$RESULTS_DIR/docker-stats-${PROFILE}-mid.txt"
echo ""

# ── Test 2: Dashboard ────────────────────────────────────────────────────
echo "================================================================"
echo " TEST 2/3: Dashboard"
echo "================================================================"
if k6 run "$SCRIPT_DIR/dashboard.js" \
    -e BASE_URL="$BASE_URL" \
    -e CAMPAIGN_ID="$CAMPAIGN_ID" \
    -e PROFILE="$PROFILE" \
    2>&1 | tee "$RESULTS_DIR/dashboard-${PROFILE}-${BACKEND_LABEL}.log"; then
    PASS_COUNT=$((PASS_COUNT + 1))
else
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi
echo ""

# ── Test 3: Proxy API ────────────────────────────────────────────────────
if [[ "$SKIP_PROXY" == "false" && -n "$API_TOKEN" ]]; then
    echo "================================================================"
    echo " TEST 3/3: Proxy API"
    echo "================================================================"
    if k6 run "$SCRIPT_DIR/proxy-api.js" \
        -e BASE_URL="$BASE_URL" \
        -e API_TOKEN="$API_TOKEN" \
        -e PROFILE="$PROFILE" \
        2>&1 | tee "$RESULTS_DIR/proxy-api-${PROFILE}-${BACKEND_LABEL}.log"; then
        PASS_COUNT=$((PASS_COUNT + 1))
    else
        FAIL_COUNT=$((FAIL_COUNT + 1))
    fi
else
    echo "--- Skipping Proxy API test (no --api-token provided or --skip-proxy) ---"
fi
echo ""

# ── Post-test Resource Snapshot ───────────────────────────────────────────
echo "--- Post-test resource usage ---"
docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" 2>/dev/null | tee "$RESULTS_DIR/docker-stats-${PROFILE}-post.txt"
echo ""

# ── Summary ───────────────────────────────────────────────────────────────
echo "================================================================"
echo " RESULTS: $PROFILE profile / $BACKEND_LABEL backend"
echo "================================================================"
echo "  Tests passed: $PASS_COUNT"
echo "  Tests failed: $FAIL_COUNT"
echo "  Results dir:  $RESULTS_DIR/"
echo ""

if [[ "$FAIL_COUNT" -gt 0 ]]; then
    echo "  VERDICT: FAIL — $PROFILE profile ($TOTAL) does NOT meet"
    echo "           performance requirements with $BACKEND_LABEL backend."
    echo ""
    echo "  Consider upgrading to the next resource profile or tuning"
    echo "  pm.max_children in docker/php/www.conf."
    exit 1
else
    echo "  VERDICT: PASS — $PROFILE profile ($TOTAL) meets"
    echo "           performance requirements with $BACKEND_LABEL backend."
    exit 0
fi
