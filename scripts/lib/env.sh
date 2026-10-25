# Sourced by the run-*.sh scripts. Exports every key of backend/.env (merged over
# .env.example) into the environment, using the same parser as ecosystem.config.cjs.
UC_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export UC_ROOT
eval "$(node -e '
const { loadEnv } = require(process.argv[1]);
const e = loadEnv();
for (const [k, v] of Object.entries(e)) {
  if (!/^[A-Z_][A-Z0-9_]*$/.test(k) || v.includes("${")) continue;
  process.stdout.write(`export ${k}=${JSON.stringify(v).replace(/\$/g, "\\$")}\n`);
}' "$UC_ROOT/scripts/lib/env.cjs")"
PHP="${PHP_BIN:-php}"
