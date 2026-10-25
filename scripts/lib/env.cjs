// Minimal .env reader shared by ecosystem.config.cjs and the render scripts.
// backend/.env is the single source of truth for hosts and ports.
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');

function parseEnvFile(file) {
  const out = {};
  if (!fs.existsSync(file)) return out;
  for (const raw of fs.readFileSync(file, 'utf8').split('\n')) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
      val = val.slice(1, -1);
    }
    out[key] = val;
  }
  return out;
}

function loadEnv() {
  const example = parseEnvFile(path.join(ROOT, 'backend', '.env.example'));
  const local = parseEnvFile(path.join(ROOT, 'backend', '.env'));
  // process.env wins so one-off overrides work: `API_BASE_PORT=9001 pm2 start ...`
  const env = { ...example, ...local };
  for (const k of Object.keys(env)) {
    if (process.env[k] !== undefined && process.env[k] !== '') env[k] = process.env[k];
  }
  return env;
}

module.exports = { ROOT, loadEnv, parseEnvFile };
