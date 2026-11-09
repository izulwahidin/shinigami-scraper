#!/usr/bin/env bash
#
# Install / remove the 10-minute cron job for the Shinigami scraper.
#
# Usage:
#   ./bin/install-cron.sh                 # install (default */10 * * * *)
#   ./bin/install-cron.sh --remove        # remove
#   SHINIGAMI_CRON_SCHEDULE="*/5 * * * *" ./bin/install-cron.sh
#   PHP_BIN=/usr/bin/php8.3 ./bin/install-cron.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CRON_TAG="# shinigami-scraper"
SCHEDULE="${SHINIGAMI_CRON_SCHEDULE:-*/10 * * * *}"
LOG_FILE="$ROOT_DIR/storage/logs/cron.log"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

quote() {
    printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

remove_entry() {
    local tmp
    tmp="$(mktemp)"
    crontab -l 2>/dev/null | grep -vF "$CRON_TAG" > "$tmp" || true
    crontab "$tmp"
    rm -f "$tmp"
}

if [[ "${1:-}" == "--remove" ]]; then
    remove_entry
    echo "Removed Shinigami scraper cron entry."
    exit 0
fi

if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
    echo "PHP binary not found. Set PHP_BIN=/path/to/php" >&2
    exit 1
fi

mkdir -p "$ROOT_DIR/storage/logs"

LINE="$SCHEDULE cd $(quote "$ROOT_DIR") && $(quote "$PHP_BIN") bin/scrape.php update --quiet >> $(quote "$LOG_FILE") 2>&1 $CRON_TAG"

TMP_FILE="$(mktemp)"
crontab -l 2>/dev/null | grep -vF "$CRON_TAG" > "$TMP_FILE" || true
printf '%s\n' "$LINE" >> "$TMP_FILE"
crontab "$TMP_FILE"
rm -f "$TMP_FILE"

echo "Installed cron entry:"
echo "  $LINE"
echo
echo "Check with: crontab -l"
echo "Logs:       $ROOT_DIR/storage/logs/scraper.log"
