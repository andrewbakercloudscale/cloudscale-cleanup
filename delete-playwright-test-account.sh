#!/usr/bin/env bash
# Kills all active Playwright test sessions via the CSDT Test Account Manager API.
#
# The playwright role is a persistent WordPress user — this script does NOT delete
# the user or the role. It only invalidates currently active sessions (e.g. to
# force a clean state before the next test run).
#
# Sessions also expire automatically after their TTL, so this script is only
# needed if you want to invalidate them early.
#
# Usage:
#   bash delete-playwright-test-account.sh
#   bash delete-playwright-test-account.sh --env path/to/.env.test

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env.test"

while [[ $# -gt 0 ]]; do
    case $1 in
        --env) ENV_FILE="$2"; shift 2 ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [[ ! -f "$ENV_FILE" ]]; then
    echo "No .env.test found — no sessions to clean up."
    echo "Set up testing from the Test Account Manager panel, then run scripts/csdt-authorise.sh."
    exit 0
fi

CSDT_SECRET=$(grep '^CSDT_TEST_SECRET='   "$ENV_FILE" | cut -d'=' -f2- | tr -d '\r')
CSDT_ROLE=$(grep '^CSDT_TEST_ROLE='       "$ENV_FILE" | cut -d'=' -f2- | tr -d '\r')
CSDT_LOGOUT_URL=$(grep '^CSDT_TEST_LOGOUT_URL=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '\r')

if [[ -z "$CSDT_SECRET" || -z "$CSDT_ROLE" || -z "$CSDT_LOGOUT_URL" ]]; then
    echo "CSDT credentials not found in $ENV_FILE — nothing to clean up."
    exit 0
fi

echo "Killing all active sessions for role: ${CSDT_ROLE}..."
RESP=$(curl -s -X POST "$CSDT_LOGOUT_URL" \
    --data-urlencode "secret=${CSDT_SECRET}" \
    --data-urlencode "role=${CSDT_ROLE}")

if echo "$RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); exit(0 if d.get('ok') else 1)" 2>/dev/null; then
    echo "All sessions invalidated for role: ${CSDT_ROLE}"
else
    echo "Logout response: $RESP"
fi
