#!/usr/bin/env bash
#
# Onboard.Ninja — first-run setup for the self-hosted Docker stack.
#
# Creates a .env from the .env.docker template and fills in every value that
# must be unique to your install: the Laravel APP_KEY, the MySQL passwords,
# and the admin login. Nothing here talks to the network.
#
#   ./quickstart.sh                 # interactive — prompts for the admin login
#   ./quickstart.sh --yes           # non-interactive — generates an admin password
#   ./quickstart.sh --force         # overwrite an existing .env (backs it up first)
#   ./quickstart.sh --port 8082     # serve on a port other than 8080
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$REPO_ROOT"

TEMPLATE=".env.docker"
TARGET=".env"
ASSUME_YES=false
FORCE=false
APP_PORT_OVERRIDE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes|-y)    ASSUME_YES=true; shift ;;
        --force|-f)  FORCE=true; shift ;;
        --port|-p)
            APP_PORT_OVERRIDE="${2:-}"
            if [[ ! "$APP_PORT_OVERRIDE" =~ ^[0-9]+$ ]] \
               || (( APP_PORT_OVERRIDE < 1 || APP_PORT_OVERRIDE > 65535 )); then
                echo "ERROR: --port needs a number between 1 and 65535." >&2
                exit 1
            fi
            shift 2
            ;;
        --help|-h)
            sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Unknown option: $1 (try --help)" >&2
            exit 1
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
if ! command -v openssl >/dev/null 2>&1; then
    echo "ERROR: openssl is required to generate secrets but was not found." >&2
    echo "       Install it (apt install openssl / brew install openssl) and re-run." >&2
    exit 1
fi

if [[ ! -f "$TEMPLATE" ]]; then
    echo "ERROR: $TEMPLATE not found. Run this from the repository root." >&2
    exit 1
fi

if [[ -f "$TARGET" && "$FORCE" != "true" ]]; then
    echo "A .env already exists — not overwriting it."
    echo
    echo "  If you want to start over:  ./quickstart.sh --force"
    echo "  (your current .env would be backed up to .env.bak first)"
    exit 1
fi

if [[ -f "$TARGET" && "$FORCE" == "true" ]]; then
    cp "$TARGET" "$TARGET.bak"
    echo "==> Existing .env backed up to .env.bak"
fi

# ---------------------------------------------------------------------------
# Warn if a MySQL data volume already exists.
#
# MySQL reads MYSQL_PASSWORD / MYSQL_ROOT_PASSWORD only when initialising an
# empty data directory. If the stack has run before, the volume keeps the
# passwords from that first boot and the freshly generated ones below will be
# silently ignored — surfacing later as "Access denied for user 'onboard'".
# ---------------------------------------------------------------------------
PROJECT_NAME="$(awk -F= '/^COMPOSE_PROJECT_NAME=/ { print $2; exit }' "$TEMPLATE" 2>/dev/null)"
PROJECT_NAME="${PROJECT_NAME:-onboard}"

if command -v docker >/dev/null 2>&1 &&
   docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qx "${PROJECT_NAME}_mysql_data"; then
    echo
    echo "WARNING: an existing MySQL data volume was found"
    echo "         (${PROJECT_NAME}_mysql_data)."
    echo
    echo "  MySQL only applies passwords when creating a fresh database, so the"
    echo "  new passwords this script generates will NOT take effect. The app"
    echo "  will fail to start with \"Access denied for user 'onboard'\"."
    echo
    echo "  Your options:"
    echo
    echo "    1. Keep your existing data — press Ctrl-C now, and copy the"
    echo "       DB_PASSWORD / DB_ROOT_PASSWORD values out of your old .env"
    echo "       (or .env.bak) into the new one after this script finishes."
    echo
    echo "    2. Start over and DESTROY the existing database:"
    echo
    echo "           docker compose down -v"
    echo
    echo "       then re-run this script."
    echo
    if [[ "$ASSUME_YES" != "true" ]]; then
        read -r -p "  Continue anyway? [y/N]: " CONFIRM_VOLUME
        case "$CONFIRM_VOLUME" in
            [yY]|[yY][eE][sS]) ;;
            *) echo "  Aborted — no changes made."; exit 1 ;;
        esac
    else
        echo "  (--yes given: continuing, but read the above before starting the stack)"
    fi
    echo
fi

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

# A URL/shell-safe secret: base64 with the awkward characters stripped.
# Trimmed to 32 chars, which is ample entropy for a database password.
gen_secret() {
    openssl rand -base64 48 | tr -d '/+=\n' | cut -c1-32
}

# Laravel's APP_KEY is literally "base64:" + base64(32 random bytes) for the
# AES-256-CBC cipher. Generating it here means the container never has to.
gen_app_key() {
    printf 'base64:%s' "$(openssl rand -base64 32)"
}

# Set KEY=VALUE in the target file, replacing any existing definition.
# Uses awk rather than sed so that /, +, and & in generated secrets are safe.
set_env() {
    local key="$1" value="$2" tmp
    tmp="$(mktemp)"
    KEY="$key" VALUE="$value" awk '
        BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"]; done = 0 }
        # Match "KEY=" at the start of a line, ignoring commented-out lines.
        index($0, k "=") == 1 { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }
    ' "$TARGET" > "$tmp"
    mv "$tmp" "$TARGET"
}

# ---------------------------------------------------------------------------
# Build the .env
# ---------------------------------------------------------------------------
cp "$TEMPLATE" "$TARGET"
echo "==> Created .env from $TEMPLATE"

echo "==> Generating APP_KEY and database passwords ..."
set_env APP_KEY          "$(gen_app_key)"
set_env DB_PASSWORD      "$(gen_secret)"
set_env DB_ROOT_PASSWORD "$(gen_secret)"

# ---------------------------------------------------------------------------
# Admin account
# ---------------------------------------------------------------------------
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
GENERATED_ADMIN_PASSWORD=false

if [[ "$ASSUME_YES" == "true" ]]; then
    ADMIN_EMAIL="admin@example.com"
    ADMIN_PASSWORD="$(gen_secret)"
    GENERATED_ADMIN_PASSWORD=true
else
    echo
    echo "--- Admin account ---"
    echo "This is the login you'll use to sign in. There is no public"
    echo "registration in the self-hosted build, so keep these safe."
    echo

    read -r -p "Admin email [admin@example.com]: " ADMIN_EMAIL
    ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"

    while true; do
        read -r -s -p "Admin password (blank to generate one): " ADMIN_PASSWORD
        echo
        if [[ -z "$ADMIN_PASSWORD" ]]; then
            ADMIN_PASSWORD="$(gen_secret)"
            GENERATED_ADMIN_PASSWORD=true
            break
        fi
        if [[ ${#ADMIN_PASSWORD} -lt 12 ]]; then
            echo "  Too short — use at least 12 characters."
            continue
        fi
        read -r -s -p "Confirm password: " ADMIN_PASSWORD_CONFIRM
        echo
        if [[ "$ADMIN_PASSWORD" != "$ADMIN_PASSWORD_CONFIRM" ]]; then
            echo "  Passwords did not match — try again."
            continue
        fi
        break
    done
fi

set_env ADMIN_EMAIL    "$ADMIN_EMAIL"
set_env ADMIN_PASSWORD "$ADMIN_PASSWORD"

# APP_URL has to track APP_PORT — Laravel builds absolute URLs (including the
# claim links baked into QR codes) from it, so a mismatch produces links that
# point at the wrong port.
if [[ -n "$APP_PORT_OVERRIDE" ]]; then
    set_env APP_PORT "$APP_PORT_OVERRIDE"
    set_env APP_URL  "http://localhost:${APP_PORT_OVERRIDE}"
    echo "==> Serving on port $APP_PORT_OVERRIDE (APP_URL updated to match)"
fi

chmod 600 "$TARGET"

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
APP_PORT="$(awk -F= '/^APP_PORT=/ { print $2; exit }' "$TARGET")"
APP_PORT="${APP_PORT:-8080}"

echo
echo "==================================================================="
echo " Setup complete — .env written (permissions set to 600)"
echo "==================================================================="
echo
echo "  Admin email:     $ADMIN_EMAIL"
if [[ "$GENERATED_ADMIN_PASSWORD" == "true" ]]; then
    echo "  Admin password:  $ADMIN_PASSWORD"
    echo
    echo "  ^ This was generated for you and is NOT shown again."
    echo "    Save it to your password manager now. It is also stored in"
    echo "    .env as ADMIN_PASSWORD if you need to retrieve it."
else
    echo "  Admin password:  (the one you entered)"
fi
echo
echo "  Start the stack with:"
echo
echo "      docker compose up -d --build"
echo
echo "  Then open http://localhost:${APP_PORT}"
echo
echo "  Ports already in use? Set APP_PORT / DB_HOST_PORT / REDIS_HOST_PORT"
echo "  in .env — see the comments in that file."
echo
echo "  Before exposing this to the internet, set APP_URL in .env to your"
echo "  real domain and put it behind HTTPS."
echo
