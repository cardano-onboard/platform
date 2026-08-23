#!/usr/bin/env bash
set -euo pipefail

# Run OWASP ZAP baseline scan and nikto against the running Docker stack.
#
# Prerequisites:
#   - Docker installed and running
#   - Application stack up on the target URL
#
# Usage:
#   ./tests/security/run-security-scan.sh [target_url]
#
# Defaults to http://localhost:8080

TARGET="${1:-http://localhost:8080}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RESULTS_DIR="$SCRIPT_DIR/results"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

mkdir -p "$RESULTS_DIR"

echo "=== Security Scan — $(date) ==="
echo "Target: $TARGET"
echo "Results: $RESULTS_DIR"
echo ""

# ── OWASP ZAP Baseline Scan ──────────────────────────────────────────────
echo "--- OWASP ZAP Baseline Scan ---"
echo "Running ZAP in Docker (baseline scan — passive scanning only)..."

docker run --rm --network=host \
    -v "$RESULTS_DIR:/zap/wrk/:rw" \
    ghcr.io/zaproxy/zaproxy:stable \
    zap-baseline.py \
    -t "$TARGET" \
    -r "zap-report-${TIMESTAMP}.html" \
    -J "zap-report-${TIMESTAMP}.json" \
    -I \
    2>&1 | tee "$RESULTS_DIR/zap-${TIMESTAMP}.log"

echo ""
echo "ZAP HTML report: $RESULTS_DIR/zap-report-${TIMESTAMP}.html"
echo "ZAP JSON report: $RESULTS_DIR/zap-report-${TIMESTAMP}.json"
echo ""

# ── Nikto HTTP Scanner ────────────────────────────────────────────────────
echo "--- Nikto HTTP Scan ---"
echo "Running nikto in Docker..."

docker run --rm --network=host \
    securecodebox/nikto \
    -h "$TARGET" \
    -output "/dev/stdout" \
    -Format txt \
    2>&1 | tee "$RESULTS_DIR/nikto-${TIMESTAMP}.log"

echo ""
echo "Nikto report: $RESULTS_DIR/nikto-${TIMESTAMP}.log"
echo ""

# ── Summary ───────────────────────────────────────────────────────────────
echo "=== Security Scan Complete ==="
echo ""
echo "Reports saved to: $RESULTS_DIR/"
echo "  - zap-report-${TIMESTAMP}.html  (OWASP ZAP — open in browser)"
echo "  - zap-report-${TIMESTAMP}.json  (OWASP ZAP — machine-readable)"
echo "  - nikto-${TIMESTAMP}.log        (Nikto findings)"
echo ""
echo "Next steps:"
echo "  1. Review ZAP HTML report for findings"
echo "  2. Review nikto log for server misconfigurations"
echo "  3. Document findings in docs/security-and-load-testing.md"
