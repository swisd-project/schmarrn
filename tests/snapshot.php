<?php

/**
 * 1. Relative path: tests/snapshot.php
 * 2. Description: The BYTE-IDENTITY GATE. Boots a private php -S on a battery
 *                 port, fetches a fixed set of public URLs, and records a
 *                 sha256 per response. `--write` captures the baseline; the
 *                 default run compares the live tree against it. Every
 *                 refactor step must answer GREEN here before it may ride.
 * 3. Expects: The repo root as argv[1] (default: the parent of this file).
 *                 Nothing else — no fixture, no network, no Vitrine.
 * 4. Provides: A manifest of sha256 hashes (tests/fixtures/baseline.json) and
 *                 a PASS/FAIL verdict for every URL.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

// The volatile bits: the host carries the port (which changes per run) and the
// session/csrf answers are random by nature. Both are normalized out BEFORE
// the hash — otherwise the gate would measure the clock, not the code.
const VOLATILE = [
    // the battery's own host:port in any absolute URL or link
    '/127\.0\.0\.1:\d+/',
    // a csrf token (32 hex) — random per session
    '/"csrf":"[a-f0-9]{64}"/',
];

$root = dirname(__DIR__);
$write = false;
foreach ($argv as $arg) {
    if ($arg === '--write') {
        $write = true;
        continue;
    }
    if ($arg !== '' && $arg[0] !== '-' && is_dir($arg)) {
        $root = rtrim($arg, '/');
    }
}
$baselineFile = $root . '/tests/fixtures/baseline.json';

/** The URLs under the gate. Public doors only — no session, no randomness. */
function urls(): array
{
    return [
        'home'        => '/',
        'page-hello'  => '/hello',
        'page-test'   => '/test',
        'page-404'    => '/nope-does-not-exist',
        'api-pages'   => '/bridge/pages',
        'api-raw'     => '/bridge/page/pages/hello',
        'api-public'  => '/bridge/public/pages/hello',
        'api-search'  => '/bridge/search?q=hello',
        'api-session' => '/bridge/session',
        'api-templates' => '/bridge/templates',
        'api-plugins' => '/bridge/plugins',
        'api-drawer'  => '/bridge/drawer',
        'api-editor-blocks' => '/bridge/editor-blocks',
        'api-pagetypes' => '/bridge/pagetypes',
        'api-core'    => '/bridge/core',
        // ——— the ERROR PATHS ———
        // The refusals are the half of the core that STEP 2 (the exception
        // refactor) touches hardest. A byte-identity gate that only watches
        // the happy pages is a gate that cannot see the refactor's risk.
        'err-404-page'   => '/bridge/page/pages/no-such-page',
        'err-404-public' => '/bridge/public/pages/no-such-page',
        'err-404-route'  => '/bridge/no-such-route',
        'err-400-slug'   => '/bridge/page/pages/NOT_A_SLUG',
        'err-400-type'   => '/bridge/page/NOT_A_TYPE/hello',
        'err-401-search' => '/bridge/search?q=x',
        'err-401-plugins' => '/bridge/plugins',
        'err-404-plugin' => '/bridge/plugin/hello/no-such-route',
        'err-404-plugin2' => '/bridge/plugin/no-such-plugin/status',
        'err-400-media'  => '/bridge/media/pages/hello',
    ];
}

/** A free TCP port in the battery range (8196+, per guardrails: the user's
 *  dev servers are sacred and battery ports never collide with them). */
function freePort(): int
{
    for ($p = 8196; $p < 8300; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:{$p}", $errno, $errstr);
        if ($sock === false) {
            continue;
        }
        fclose($sock);
        return $p;
    }
    fwrite(STDERR, "no free battery port in 8196..8299\n");
    exit(2);
}

/** Boot the private server; returns [pid, base-url]. The child is ours alone. */
function boot(string $root): array
{
    $port = freePort();
    // The array form: NO shell wrapper. The guardrails lesson says a
    // proc_open'd shell orphans the php child on signal — the array form
    // spawns php itself, so SIGKILL reaches the server we started.
    $cmd = ['php', '-S', "127.0.0.1:{$port}", $root . '/index.php'];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $root);
    if (!is_resource($proc)) {
        fwrite(STDERR, "could not boot the battery server\n");
        exit(2);
    }
    // The pipe must not fill: drain it in the background.
    foreach ([1, 2] as $fd) {
        stream_set_blocking($pipes[$fd], false);
    }
    $base = "http://127.0.0.1:{$port}";
    // Wait for the door to answer (knock, never kick).
    for ($i = 0; $i < 100; $i++) {
        usleep(50000);
        $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
        if ($sock !== false) {
            fclose($sock);
            return [$proc, $base, $pipes];
        }
    }
    fwrite(STDERR, "the battery server never opened its door\n");
    proc_terminate($proc, SIGKILL);
    exit(2);
}

/** One GET: [status, normalized body]. */
function fetch(string $base, string $path): array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'ignore_errors' => true,
        'timeout' => 20,
        'header' => "Accept: */*\r\n",
    ]]);
    $raw = @file_get_contents($base . $path, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
            $status = (int)$m[1];
        }
    }
    $body = (string)$raw;
    foreach (VOLATILE as $pattern) {
        $body = (string)preg_replace($pattern, '__VOLATILE__', $body);
    }
    return [$status, $body];
}

// ——— the ride ———

[$proc, $base, $pipes] = boot($root);

$results = [];
foreach (urls() as $name => $path) {
    [$status, $body] = fetch($base, $path);
    $results[$name] = [
        'status' => $status,
        'sha256' => hash('sha256', $body),
        'bytes'  => strlen($body),
    ];
}

// The knife rests: our server, our PID, our port.
proc_terminate($proc, SIGKILL);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
proc_close($proc);

if ($write) {
    @mkdir(dirname($baselineFile), 0775, true);
    file_put_contents($baselineFile, json_encode([
        'note' => 'Byte-identity baseline — captured by tests/snapshot.php --write. '
            . 'Every refactor step must keep every sha256 identical.',
        'urls'  => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "baseline written: {$baselineFile}\n";
    foreach ($results as $name => $r) {
        echo "  {$name}: {$r['status']} {$r['sha256']} ({$r['bytes']} bytes)\n";
    }
    exit(0);
}

// ——— the comparison ———

if (!is_file($baselineFile)) {
    fwrite(STDERR, "no baseline at {$baselineFile} — run with --write first\n");
    exit(2);
}
$baseline = json_decode((string)file_get_contents($baselineFile), true);
$expected = (array)($baseline['urls'] ?? []);

$pass = 0;
$fail = 0;
foreach ($results as $name => $r) {
    $want = $expected[$name] ?? null;
    if ($want === null) {
        echo "SKIP  {$name}: no baseline entry (new URL)\n";
        continue;
    }
    if ((int)$want['status'] !== (int)$r['status']) {
        $fail++;
        echo "FAIL  {$name}: status {$want['status']} → {$r['status']}\n";
        continue;
    }
    if ((string)$want['sha256'] !== (string)$r['sha256']) {
        $fail++;
        echo "FAIL  {$name}: body changed ({$want['sha256']} → {$r['sha256']}, "
            . "{$want['bytes']} → {$r['bytes']} bytes)\n";
        continue;
    }
    $pass++;
    echo "PASS  {$name}: {$r['status']} {$r['sha256']}\n";
}

echo "\nsnapshot: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
