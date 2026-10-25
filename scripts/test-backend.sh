#!/usr/bin/env bash
# Pest with JUnit + coverage into docs/testing/results/. Coverage needs Xdebug or PCOV:
# loaded already, or set XDEBUG_EXTENSION=/path/to/xdebug.so in backend/.env (e.g. Herd's build).
set -uo pipefail
UC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# read only what we need: exporting all of backend/.env would override phpunit.xml's test settings
envval() { grep -E "^$1=" "$UC_ROOT/backend/.env" 2>/dev/null | tail -1 | cut -d= -f2-; }
PHP="${PHP_BIN:-$(envval PHP_BIN)}"; PHP="${PHP:-php}"
XDEBUG_EXTENSION="${XDEBUG_EXTENSION:-$(envval XDEBUG_EXTENSION)}"
OUT="$UC_ROOT/docs/testing/results"
mkdir -p "$OUT"
cd "$UC_ROOT/backend"

ARGS=(-d memory_limit=1G)
MODULES="$("$PHP" -m)"
if grep -qixE "xdebug|pcov" <<<"$MODULES"; then
  COVER=1; ARGS+=(-d xdebug.mode=coverage)
elif [ -n "${XDEBUG_EXTENSION:-}" ] && [ -f "$XDEBUG_EXTENSION" ]; then
  COVER=1; ARGS+=(-d "zend_extension=$XDEBUG_EXTENSION" -d xdebug.mode=coverage)
else
  COVER=0; echo "(no coverage driver: install pcov/xdebug or set XDEBUG_EXTENSION; running without coverage)"
fi

PEST=(vendor/bin/pest --colors=never --log-junit "$OUT/backend-junit.xml")
[ "$COVER" = 1 ] && PEST+=(--coverage --coverage-clover "$OUT/backend-coverage.xml" --coverage-text="$OUT/backend-coverage.txt")
"$PHP" "${ARGS[@]}" "${PEST[@]}" "$@"
CODE=$?
[ "$COVER" = 1 ] && grep -E "Lines:" "$OUT/backend-coverage.txt" | head -1
exit $CODE
