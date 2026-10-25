// PM2 definition of every UniConnect process (no Docker).
//   make up            -> pm2 start ecosystem.config.cjs
//   make scale API=3   -> pm2 scale api 3
// Hosts/ports come from backend/.env (see scripts/lib/env.cjs).
const path = require('path');
const { ROOT, loadEnv } = require('./scripts/lib/env.cjs');
const { render } = require('./scripts/render-traefik.cjs');

const e = loadEnv();
render();

const php = e.PHP_BIN || 'php';
const bin = (name) => (e.MYSQL_BIN_DIR ? path.join(e.MYSQL_BIN_DIR, name) : name);
const minioBin = e.MINIO_BIN_DIR ? path.join(e.MINIO_BIN_DIR, 'minio') : 'minio';
const backend = path.join(ROOT, 'backend');
const logs = path.join(ROOT, 'storage', 'logs');

const common = {
  cwd: ROOT,
  autorestart: true,
  max_restarts: 50,
  restart_delay: 1000,
  merge_logs: true,
  time: true,
  out_file: path.join(logs, 'pm2-out.log'),
  error_file: path.join(logs, 'pm2-err.log'),
};
const proc = (name, extra) => ({
  ...common,
  name,
  out_file: path.join(logs, `${name}.out.log`),
  error_file: path.join(logs, `${name}.err.log`),
  ...extra,
});

module.exports = {
  apps: [
    proc('mysql-primary', {
      script: bin('mysqld'),
      args: `--defaults-file=${path.join(ROOT, 'storage', 'mysql', 'primary.cnf')}`,
      interpreter: 'none',
      kill_timeout: 30000,
    }),
    proc('mysql-replica', {
      script: bin('mysqld'),
      args: `--defaults-file=${path.join(ROOT, 'storage', 'mysql', 'replica.cnf')}`,
      interpreter: 'none',
      kill_timeout: 30000,
    }),
    proc('minio', {
      script: minioBin,
      args: `server ${path.join(ROOT, 'storage', 'minio')} --address :${e.MINIO_PORT} --console-address ${e.MINIO_CONSOLE_BIND || '127.0.0.1'}:${e.MINIO_CONSOLE_PORT}`,
      interpreter: 'none',
      env: { MINIO_ROOT_USER: e.AWS_ACCESS_KEY_ID, MINIO_ROOT_PASSWORD: e.AWS_SECRET_ACCESS_KEY },
    }),
    proc('traefik', {
      script: 'traefik',
      args: `--configFile=${path.join(ROOT, 'infra', 'traefik', 'traefik.yml')}`,
      interpreter: 'none',
      env: { UC_ROOT: ROOT, WEB_PORT: e.WEB_PORT, MINIO_PORT: e.MINIO_PORT, AWS_BUCKET: e.AWS_BUCKET },
    }),
    proc('api', {
      script: path.join(ROOT, 'scripts', 'run-api.sh'),
      interpreter: 'bash',
      instances: Number(e.API_INSTANCES || 2),
      exec_mode: 'fork',
      kill_signal: 'SIGTERM',
      kill_timeout: 8000,
    }),
    proc('reverb', {
      script: path.join(ROOT, 'scripts', 'run-reverb.sh'),
      interpreter: 'bash',
      instances: Number(e.REVERB_INSTANCES || 2),
      exec_mode: 'fork',
      kill_signal: 'SIGTERM',
      kill_timeout: 8000,
    }),
    proc('registry', {
      script: php,
      args: 'artisan registry:sync',
      cwd: backend,
      interpreter: 'none',
    }),
    proc('worker', {
      script: path.join(ROOT, 'scripts', 'run-with-heartbeat.sh'),
      args: `worker ${php} artisan horizon`,
      interpreter: 'bash',
      kill_signal: 'SIGTERM',
      kill_timeout: 15000,
    }),
    proc('scheduler', {
      script: path.join(ROOT, 'scripts', 'run-with-heartbeat.sh'),
      args: `scheduler ${php} artisan schedule:work`,
      interpreter: 'bash',
      kill_signal: 'SIGTERM',
    }),
    proc('web', {
      script: path.join(ROOT, 'frontend', 'node_modules', '.bin', 'serve'),
      args: `-s ${path.join(ROOT, 'frontend', 'dist')} -l tcp://127.0.0.1:${e.WEB_PORT} --no-clipboard -c ${path.join(ROOT, 'frontend', 'serve.json')}`,
      interpreter: 'none',
    }),
  ],
};
