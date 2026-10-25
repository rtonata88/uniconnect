#!/usr/bin/env bash
# NFR-8: Lighthouse (mobile) on the PWA + Chrome's own installability check.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
source "$HERE/../scripts/lib/env.sh"
OUT="$UC_ROOT/docs/testing/results"
URL="${BASE_URL:-$APP_URL}/login"
CHROME="$(cd "$UC_ROOT/frontend" && node -e "console.log(require('@playwright/test').chromium.executablePath())")"
export CHROME_PATH="$CHROME"
cd "$UC_ROOT/frontend"
npx --yes lighthouse@12 "$URL" --quiet \
  --chrome-flags="--headless=new --ignore-certificate-errors --no-sandbox" \
  --only-categories=performance,accessibility,best-practices,seo \
  --output=json --output-path="$OUT/lighthouse.json" > /dev/null 2>&1
node -e "const r=require('$OUT/lighthouse.json');const c=r.categories;console.log(JSON.stringify(Object.fromEntries(Object.entries(c).map(([k,v])=>[k,Math.round(v.score*100)]))))"
node "$HERE/pwa-check.mjs" "${BASE_URL:-$APP_URL}" "$OUT/pwa-installability.json"
