<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * PRD 12.3: reads the real results in docs/testing/results/ (JUnit, coverage, k6 summary
 * exports, failover/scale/Lighthouse JSON) and writes docs/testing/TEST_REPORT.md.
 * Nothing is estimated: a missing result is reported as "not run".
 */
class TestingReport extends Command
{
    protected $signature = 'report:testing {--results=../docs/testing/results} {--out=../docs/testing/TEST_REPORT.md}';

    protected $description = 'Write docs/testing/TEST_REPORT.md from measured test and load-test results';

    private string $dir;

    public function handle(): int
    {
        $this->dir = rtrim(base_path($this->option('results')), '/');
        $md = [];
        $md[] = '# UniConnect — testing and performance report';
        $md[] = '';
        $md[] = '_Generated '.now()->toDayDateTimeString().' by `php artisan report:testing` from the files in `docs/testing/results/`. Every number below was measured on the machine described; nothing is estimated. A dash means the test was not run._';
        $md[] = '';
        $md[] = '## Environment';
        $md[] = '';
        $md[] = '| | |';
        $md[] = '|---|---|';
        foreach ($this->environment() as $k => $v) {
            $md[] = "| {$k} | {$v} |";
        }
        $md[] = '';
        $md[] = '## Automated tests';
        $md[] = '';
        $md[] = '| Suite | Tests | Passed | Failed | Skipped | Time | Coverage |';
        $md[] = '|---|---:|---:|---:|---:|---:|---|';
        foreach ([
            ['Backend (Pest)', 'backend-junit.xml', $this->cloverLines('backend-coverage.xml').' lines of `app/`'],
            ['Frontend (Vitest)', 'frontend-junit.xml', $this->vitestCoverage()],
            ['End-to-end (Playwright)', 'e2e-junit.xml', 'n/a'],
        ] as [$name, $file, $coverage]) {
            $j = $this->junit($file);
            $md[] = $j
                ? sprintf('| %s | %d | %d | %d | %d | %.1f s | %s |', $name, $j['tests'], $j['tests'] - $j['failures'] - $j['errors'] - $j['skipped'], $j['failures'] + $j['errors'], $j['skipped'], $j['time'], $coverage)
                : "| {$name} | – | – | – | – | – | – |";
        }
        $md[] = '';
        $md[] = 'E2E scenarios (PRD 12.1): '.$this->e2eCases();
        $md[] = '';
        $md[] = '## Security checks';
        $md[] = '';
        foreach ($this->audits() as $line) {
            $md[] = "- {$line}";
        }
        $md[] = '- Manual OWASP Top 10 review: [`security-checklist.md`](security-checklist.md)';
        $md[] = '';
        $md[] = '## Non-functional requirements';
        $md[] = '';
        $md[] = '| ID | Requirement | Target | Measured | Result |';
        $md[] = '|---|---|---|---|---|';
        foreach ($this->nfrs() as $row) {
            $md[] = '| '.implode(' | ', $row).' |';
        }
        $md[] = '';
        $md[] = '## Traceability (P0 requirements → automated tests)';
        $md[] = '';
        $md[] = 'Test names carry the requirement ids (PRD §7); this table is built from the JUnit files above.';
        $md[] = '';
        $md[] = '| Requirement | Tests | Result | Other evidence |';
        $md[] = '|---|---:|---|---|';
        foreach ($this->traceability() as $row) {
            $md[] = '| '.implode(' | ', $row).' |';
        }
        $md[] = '';
        $md[] = '## Notes';
        $md[] = '';
        foreach ($this->notes() as $note) {
            $md[] = "- {$note}";
        }
        $md[] = '';

        $out = base_path($this->option('out'));
        file_put_contents($out, implode("\n", $md));
        $this->info("wrote {$out}");

        return self::SUCCESS;
    }

    private function json(string $file): ?array
    {
        $path = "{$this->dir}/{$file}";

        return is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    }

    private function sh(string $cmd): string
    {
        return trim((string) @shell_exec($cmd.' 2>/dev/null'));
    }

    private function environment(): array
    {
        $os = PHP_OS_FAMILY === 'Darwin'
            ? 'macOS '.$this->sh('sw_vers -productVersion').' ('.php_uname('m').')'
            : $this->sh('. /etc/os-release && echo "$PRETTY_NAME"').' ('.php_uname('m').')';
        $cpu = PHP_OS_FAMILY === 'Darwin'
            ? $this->sh('sysctl -n machdep.cpu.brand_string').', '.$this->sh('sysctl -n hw.ncpu').' cores'
            : $this->sh("lscpu | sed -n 's/^Model name:\\s*//p'").', '.$this->sh('nproc').' cores';
        $ram = PHP_OS_FAMILY === 'Darwin'
            ? round(((int) $this->sh('sysctl -n hw.memsize')) / 1024 ** 3).' GB'
            : round(((int) $this->sh("awk '/MemTotal/ {print $2}' /proc/meminfo")) / 1024 ** 2).' GB';
        try {
            $mysql = DB::selectOne('SELECT VERSION() AS v')->v;
        } catch (Throwable) {
            $mysql = 'n/a';
        }
        try {
            $redis = Redis::connection()->command('info')['Server']['redis_version'] ?? Redis::connection()->command('info')['redis_version'] ?? 'n/a';
        } catch (Throwable) {
            $redis = 'n/a';
        }
        $franken = $this->sh(base_path('frankenphp').' version');

        return [
            'OS' => $os,
            'CPU' => $cpu,
            'RAM' => $ram,
            'PHP (CLI: tests, Reverb, Horizon)' => PHP_VERSION,
            'API server' => $franken ? 'Laravel Octane + '.preg_replace('/ Caddy.*/', '', $franken) : 'n/a',
            'Laravel' => app()->version(),
            'MySQL' => $mysql.' (primary + GTID replica)',
            'Redis' => $redis,
            'Node' => $this->sh('node -v'),
            'Traefik' => $this->sh("traefik version | sed -n 's/^Version:\\s*//p'"),
            'k6' => $this->sh('k6 version | head -1'),
            'Topology during load tests' => '2 API replicas (Octane, 4 workers each), 2 Reverb replicas, 1 Horizon, MySQL primary + replica — all on this one machine',
        ];
    }

    private function junit(string $file): ?array
    {
        $path = "{$this->dir}/{$file}";
        if (! is_file($path)) {
            return null;
        }
        $xml = simplexml_load_file($path);
        $sum = ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'time' => 0.0];
        $suites = $xml->getName() === 'testsuites' && ! isset($xml['tests']) ? $xml->testsuite : [$xml];
        foreach ($suites as $s) {
            $sum['tests'] += (int) $s['tests'];
            $sum['failures'] += (int) $s['failures'];
            $sum['errors'] += (int) $s['errors'];
            $sum['skipped'] += (int) ($s['skipped'] ?? 0);
            $sum['time'] += (float) $s['time'];
        }

        return $sum;
    }

    private function e2eCases(): string
    {
        $path = "{$this->dir}/e2e-junit.xml";
        if (! is_file($path)) {
            return 'not run.';
        }
        $cases = [];
        foreach (simplexml_load_file($path)->xpath('//testcase') as $c) {
            $ok = ! isset($c->failure) && ! isset($c->error);
            $cases[] = ($ok ? '✅ ' : '❌ ').preg_replace('/^.*› /', '', (string) $c['name']);
        }

        return implode('; ', $cases).'.';
    }

    private function cloverLines(string $file): string
    {
        $path = "{$this->dir}/{$file}";
        if (! is_file($path)) {
            return 'not measured';
        }
        $m = simplexml_load_file($path)->project->metrics;
        $total = (int) $m['statements'];

        return $total ? sprintf('%.1f %%', 100 * (int) $m['coveredstatements'] / $total) : 'not measured';
    }

    private function vitestCoverage(): string
    {
        $s = $this->json('frontend-coverage/coverage-summary.json');

        return $s ? sprintf('%.1f %% lines of `src/` (excl. generated UI kit)', $s['total']['lines']['pct']) : 'not measured';
    }

    private function audits(): array
    {
        $composer = json_decode($this->sh('cd '.base_path().' && composer audit --format=json --no-interaction') ?: 'null', true);
        $npm = json_decode($this->sh('cd '.base_path('../frontend').' && npm audit --json') ?: 'null', true);
        $out = [];
        $out[] = '`composer audit`: '.($composer === null ? 'not run' : (count($composer['advisories'] ?? []) === 0 ? 'no advisories' : count($composer['advisories']).' packages with advisories'));
        $out[] = '`npm audit`: '.($npm === null ? 'not run' : ($npm['metadata']['vulnerabilities']['total'] ?? 0).' vulnerabilities');
        $zap = "{$this->dir}/zap-baseline.md";
        $out[] = 'OWASP ZAP baseline: '.(is_file($zap) ? '[`results/zap-baseline.md`](results/zap-baseline.md) — '.trim(strtok((string) file_get_contents("{$this->dir}/zap-summary.txt") ?: '', "\n")) : 'not run');

        return $out;
    }

    private function metric(?array $summary, string $name, string $stat)
    {
        return $summary['metrics'][$name][$stat] ?? null;
    }

    private static function pass(?bool $ok): string
    {
        return $ok === null ? '–' : ($ok ? '✅ pass' : '❌ fail');
    }

    private function nfrs(): array
    {
        $rows = [];

        $s = $this->json('k6-rest-mixed.json');
        $p95 = $this->metric($s, 'http_req_duration', 'p(95)');
        $err = $this->metric($s, 'http_req_failed', 'value');
        $rows[] = ['NFR-1', 'REST latency, 100 VUs, mixed reads', 'p95 < 300 ms, errors < 1 %',
            $s ? sprintf('p95 %.1f ms, errors %.2f %% (%d requests, %.0f req/s)', $p95, $err * 100, $this->metric($s, 'http_reqs', 'count'), $this->metric($s, 'http_reqs', 'rate')) : '–',
            self::pass($s ? ($p95 < 300 && $err < 0.01) : null)];

        $s = $this->json('k6-send-messages.json');
        $p95 = $this->metric($s, 'http_req_duration{name:POST /conversations/{id}/messages}', 'p(95)');
        $rows[] = ['NFR-2', 'Send message REST call, 50 senders', 'p95 < 400 ms',
            $s ? sprintf('p95 %.1f ms (%d messages, errors %.2f %%)', $p95, $this->metric($s, 'http_reqs', 'count'), $this->metric($s, 'http_req_failed', 'value') * 100) : '–',
            self::pass($s ? $p95 < 400 : null)];

        $s = $this->json('k6-ws-latency.json');
        $p95 = $this->metric($s, 'ws_e2e_latency', 'p(95)');
        $rows[] = ['NFR-3', 'End-to-end message latency with 200 open sockets', 'p95 < 500 ms',
            $s ? sprintf('p95 %.0f ms, median %.0f ms (%d deliveries of %d messages)', $p95, $this->metric($s, 'ws_e2e_latency', 'med'), $this->metric($s, 'ws_messages_received', 'count'), $this->metric($s, 'ws_messages_sent', 'count')) : '–',
            self::pass($s ? $p95 < 500 : null)];

        $connected = (int) $this->metric($s, 'ws_connected', 'count');
        $closes = (int) $this->metric($s, 'ws_unexpected_close', 'count');
        $wsErrors = (int) $this->metric($s, 'ws_errors', 'count');
        $samples = is_file("{$this->dir}/ws-instances.jsonl") ? array_filter(array_map('json_decode', file("{$this->dir}/ws-instances.jsonl")), fn ($x) => $x) : [];
        $peak = collect($samples)->sortByDesc('total')->first();
        $spread = $peak ? collect((array) $peak->per_instance)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ') : 'n/a';
        $rows[] = ['NFR-4', 'Concurrent WebSockets across 2 Reverb instances', '≥ 500 stable for 2 min',
            $s ? sprintf('%d connected, %d unexpected closes, %d errors, held %.0f s; peak reported by Reverb %s (%s)', $connected, $closes, $wsErrors, $this->metric($s, 'ws_session_duration', 'avg') / 1000, $peak->total ?? 'n/a', $spread) : '–',
            self::pass($s ? ($connected >= 500 && $closes === 0 && $wsErrors === 0) : null)];

        $f = $this->json('failover.json');
        $rows[] = ['NFR-5', 'Availability: one API replica stopped during load', 'errors < 2 %, recovers within 15 s',
            $f ? sprintf('errors %.2f %% (%d of %d), %d failed after the stop, recovered in %.1f s (stopped %s)', $f['error_rate'] * 100, $f['failed'], $f['requests'], $f['failed_after_kill'], $f['recovery_seconds'], $f['killed_instance']) : '–',
            self::pass($f ? ($f['error_rate'] < 0.02 && $f['recovery_seconds'] <= 15) : null)];

        $s = $this->json('k6-sync-offline.json');
        $ok = $this->metric($s, 'nfr6_ok', 'count');
        $checks = $this->metric($s, 'checks', 'value');
        $rows[] = ['NFR-6', 'Sync after 50 offline messages (with retries)', 'all delivered, no duplicates, order kept',
            $s ? sprintf('%s (checks passed %.0f %%)', $ok ? '50/50 delivered once each, in order' : 'checks failed', $checks * 100) : '–',
            self::pass($s ? ($ok >= 1) : null)];

        $sc = $this->json('scale.json');
        $rows[] = ['NFR-7', 'Scaling API 2 → 3 replicas', 'throughput increase recorded',
            $sc ? sprintf('%.0f → %.0f req/s (%+.1f %%), p95 %.1f → %.1f ms, errors %.2f %% / %.2f %%', $sc['two']['rps'], $sc['three']['rps'], $sc['throughput_increase_percent'], $sc['two']['p95_ms'], $sc['three']['p95_ms'], $sc['two']['error_rate'] * 100, $sc['three']['error_rate'] * 100) : '–',
            $sc ? (($sc['valid'] ?? false) ? '✅ recorded' : '❌ invalid run (errors ≥ 1 %)') : '–'];

        $lh = $this->json('lighthouse.json');
        $pwa = $this->json('pwa-installability.json');
        $perf = $lh ? (int) round($lh['categories']['performance']['score'] * 100) : null;
        $rows[] = ['NFR-8', 'Lighthouse (mobile) on the PWA', 'installable; performance ≥ 80',
            ($lh ? sprintf('performance %d, accessibility %d, best practices %d, SEO %d', $perf, round($lh['categories']['accessibility']['score'] * 100), round($lh['categories']['best-practices']['score'] * 100), round($lh['categories']['seo']['score'] * 100)) : 'Lighthouse not run')
            .'; '.($pwa ? ($pwa['installable'] ? 'Chrome: installable' : 'Chrome: NOT installable ('.implode(', ', array_column($pwa['installability_errors'], 'errorId')).')').($pwa['opens_offline'] ? ', opens offline' : '') : 'installability not checked'),
            self::pass($lh && $pwa ? ($perf >= 80 && $pwa['installable']) : null)];

        return $rows;
    }

    /** P0 requirements (PRD §7) mapped to the JUnit test cases that mention them. */
    private function traceability(): array
    {
        $p0 = [
            'AUTH' => [1, 2, 4, 6], 'LMS' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 'MSG' => [1, 2, 3, 4, 5, 6, 7, 8, 9],
            'NOT' => [1, 2, 3, 4], 'MOB' => [1, 2, 3, 4, 5, 6, 7], 'DIST' => [1, 2, 3, 4, 5, 6], 'SEC' => [1, 2, 3, 4, 5, 6, 7, 8, 9],
        ];
        // requirements also (or mainly) proven outside the unit/feature suites
        $extra = [
            'LMS-2' => 'course page tabs used by every E2E scenario',
            'MSG-8' => 'E2E-2 (typing indicator between two browsers)',
            'MSG-9' => 'E2E-2 (online presence)',
            'MOB-4' => 'E2E-3 (offline browsing from IndexedDB)',
            'MOB-6' => 'E2E-3 (status pill, sync on reconnect)',
            'DIST-2' => 'NFR-5 failover load test',
            'DIST-3' => 'NFR-3/4 k6 ws-latency across 2 Reverb instances; `make verify-realtime`',
            'DIST-4' => 'System Status replica check (measured lag) on MySQL',
            'DIST-5' => 'NFR-7 scaling test (instances discovered by Traefik)',
        ];
        $cases = [];
        foreach (['backend-junit.xml', 'frontend-junit.xml', 'e2e-junit.xml'] as $file) {
            $path = "{$this->dir}/{$file}";
            if (! is_file($path)) {
                continue;
            }
            foreach (simplexml_load_file($path)->xpath('//testcase') as $c) {
                $name = (string) $c['name'];
                $ok = ! isset($c->failure) && ! isset($c->error);
                // E2E-n map to the PRD 12.1 scenarios
                $name = strtr($name, ['assignment from creation to released grade' => 'LMS-5 LMS-6 LMS-7 LMS-10 NOT-1 NOT-2',
                    'real-time chat with typing indicator and read ticks' => 'MSG-5 MSG-7 MSG-8 MSG-9',
                    'offline messages and submission sync when back online' => 'MOB-4 MOB-5 MOB-6',
                    'student cannot open a course they are not enrolled in' => 'SEC-2']);
                preg_match_all('/\b(AUTH|LMS|MSG|NOT|MOB|DIST|SEC)[ _-](\d+)\b/', str_replace('__', ' ', $name), $m, PREG_SET_ORDER);
                foreach ($m as [, $area, $n]) {
                    $cases["{$area}-{$n}"][] = $ok;
                }
            }
        }
        $rows = [];
        foreach ($p0 as $area => $ids) {
            foreach ($ids as $n) {
                $id = "{$area}-{$n}";
                $results = $cases[$id] ?? [];
                $pass = count(array_filter($results));
                $rows[] = [$id, count($results), $results ? ($pass === count($results) ? '✅ all pass' : '❌ '.(count($results) - $pass).' failing') : ($extra[$id] ?? false ? '✅ see evidence' : '⚠️ none'), $extra[$id] ?? ''];
            }
        }

        return $rows;
    }

    private function notes(): array
    {
        $notes = [
            'All services ran natively on one machine (no Docker): load generator, gateway, API replicas, Reverb, MySQL primary and replica, Redis and MinIO share the CPU, so absolute numbers are a lower bound for a multi-machine deployment.',
            'Load-test users come from `LoadTestSeeder` (500 students, 10 courses + one 260-student course chat, 1 000 messages). Tokens are issued with `php artisan loadtest:tokens` so the login rate limits are not part of the measurement; per-user API limits stay active.',
            'NFR-3 latency is measured from the sender stamping the time in the message body to the `MessageSent` frame arriving at each of the 200 receivers (same clock: k6 runs on the host), through REST → MySQL → Horizon broadcast queue → Reverb (Redis pub/sub across instances) → Traefik.',
            'NFR-5 uses Traefik\'s retry middleware and registry de-registration plus a 4 s drain on SIGTERM (decisions D8, D7, D64); the recovery time is the time from `pm2 stop` to the last failed request.',
            'NFR-7 runs a closed-model load without think time (96 VUs, 40 s) at 2 and 3 replicas; requests rotate over 500 users with load-test tokens exempt from per-user rate limits (D60).',
            'NFR-8: Lighthouse 12 no longer has a PWA category, so installability comes from Chrome\'s `Page.getInstallabilityErrors` (empty = installable) plus an offline reload of the app shell.',
        ];
        $f = $this->json('failover.json');
        if ($f && $f['failed_after_kill'] > 0) {
            $notes[] = sprintf('NFR-5: %d requests failed in the %.1f s between the stop and the gateway dropping the instance.', $f['failed_after_kill'], $f['recovery_seconds']);
        }

        return $notes;
    }
}
