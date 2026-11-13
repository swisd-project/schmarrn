<?php

/**
 * 1. Relative path: tests/ware-battery.php
 * 2. Description: THE WARE-BATTERY HOOK (2026-11-13, user order): the core's
 *                 rig, harness and a live admin session — handed to any
 *                 ware's OWN battery file. The cases live in the ware's repo,
 *                 never in the core; the core only builds the stage and
 *                 understands none of the ware's words (the panel law,
 *                 test-shaped). A ware that ships no battery.php is simply
 *                 not tested here — the hook is a door, not a mandate.
 * 3. Expects: Usage: php tests/ware-battery.php <ware-dir> — <ware-dir> is
 *                 the ware's folder (its manifest's home) carrying
 *                 battery.php. PHP 8.4+.
 * 4. Provides: In battery.php's scope: $base (the rig's URL), $rig (its
 *                 root), $ware (the ware's dir), $wareName, $cookie/$csrf
 *                 (a logged-in admin session), and the helpers wreq()
 *                 ([status, headers-map, body], plain — the ware shapes its
 *                 own request law on top) and jdec(). The runner owns the
 *                 teardown and the verdict — battery.php defines cases and
 *                 may `return;` early (bail), never calls Harness::verdict.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require_once __DIR__ . '/rig.php';
require_once __DIR__ . '/harness.php';

$ware = rtrim((string)($argv[1] ?? ''), '/');
if ($ware === '' || !is_dir($ware) || !is_file($ware . '/battery.php')) {
    fwrite(STDERR, "usage: php tests/ware-battery.php <ware-dir carrying battery.php>\n");
    exit(2);
}
$wareName = basename($ware);
$root = dirname(__DIR__);
$rig = rigBuild($root, ['bienenstich' => false, 'wares' => [$wareName => $ware]]);

$port = 0;
for ($p = 8196; $p < 8300; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:{$p}", $errno, $errstr);
    if ($sock !== false) {
        fclose($sock);
        $port = $p;
        break;
    }
}
if ($port === 0) {
    fwrite(STDERR, "no free battery port\n");
    rigDestroy($rig);
    exit(2);
}

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", $rig . '/index.php'],
    $descriptors,
    $pipes,
    $rig
);
if (!is_resource($proc)) {
    fwrite(STDERR, "could not boot the rig server\n");
    rigDestroy($rig);
    exit(2);
}
foreach ([1, 2] as $fd) {
    stream_set_blocking($pipes[$fd], false);
}
$base = "http://127.0.0.1:{$port}";
$up = false;
for ($i = 0; $i < 100; $i++) {
    usleep(50000);
    $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
    if ($sock !== false) {
        fclose($sock);
        $up = true;
        break;
    }
}
if (!$up) {
    fwrite(STDERR, "the rig never opened its door\n");
    proc_terminate($proc, SIGKILL);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($proc);
    rigDestroy($rig);
    exit(2);
}

/** The plain HTTP helper: [status, headers-map, body]. The ware's battery
 *  shapes its own request law on top of this — the core knows no keys. */
function wreq(string $method, string $path, ?string $body = null, array $headers = []): array
{
    global $base;
    $opts = ['http' => [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => 20,
        'header' => $headers,
    ]];
    if ($body !== null) {
        $opts['http']['content'] = $body;
        $opts['http']['header'][] = 'Content-Type: application/json';
    }
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($base . $path, false, $ctx);
    $status = 0;
    $map = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
            $status = (int)$m[1];
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $map[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return [$status, $map, (string)$raw];
}

if (!function_exists('jdec')) {
    /** The JSON of a response, or null when it is not JSON. */
    function jdec(string $body): ?array
    {
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }
}

// the live admin session the battery may spend
$login = jdec((string)file_get_contents($base . '/bridge/login', false, stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => (string)json_encode(['user' => 'admin', 'password' => 'rig-test-pw']),
    'ignore_errors' => true,
]])));
$cookie = '';
$csrf = is_array($login) ? (string)($login['csrf'] ?? '') : '';
foreach ($http_response_header ?? [] as $line) {
    if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]*)/i', $line, $m) === 1) {
        $cookie = $m[1];
    }
}
if ($cookie === '' || $csrf === '') {
    fwrite(STDERR, "the rig's admin session never opened\n");
    proc_terminate($proc, SIGKILL);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($proc);
    rigDestroy($rig);
    exit(2);
}

// the ware's own words, run on the core's stage
require $ware . '/battery.php';

// ——— the teardown + the verdict (the runner's, always) ———
proc_terminate($proc, SIGKILL);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
proc_close($proc);
rigDestroy($rig);

Harness::verdict("{$wareName} ware battery");
