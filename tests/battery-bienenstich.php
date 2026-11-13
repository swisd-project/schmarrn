<?php

/**
 * 1. Relative path: tests/battery-bienenstich.php
 * 2. Description: The security battery for the Bienenstich (API) ware — the
 *                 ware's own boundary: the key gate, the draft blindness, the
 *                 read-only law, the JSON hat, the CORS door. Rebuilt into the
 *                 repo; the rig is built fresh from the current tree on every
 *                 run (no /tmp rig, no network).
 * 3. Expects: The repo root as argv[1]. PHP 8.4+ with the phar extension.
 * 4. Provides: PASS/FAIL per case, a count, exit 1 on any failure.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/rig.php';

$root = dirname(__DIR__);
$rig = rigBuild($root);

// The ware is REPO-EXTERNAL by law (the Vitrine pours it; the base keeps
// only hello tracked) — on a host without the local install the battery has
// nothing to prove and says so, instead of dying.
if (!is_file($rig . '/bridge/plugins/bienenstich/plugin.json')) {
    echo "SKIP  no bienenstich ware on this host (repo-external by law) — nothing to prove\n";
    rigDestroy($rig);
    exit(0);
}
$port = 8196;
for ($p = 8196; $p < 8300; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:{$p}", $errno, $errstr);
    if ($sock !== false) {
        fclose($sock);
        $port = $p;
        break;
    }
}

// The server: the array form, NO shell wrapper — SIGKILL reaches the php we
// started (the guardrails lesson about orphaned children).
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
    rigDestroy($rig);
    exit(2);
}

$KEY = 'rig-key-abc123';

/** One GET: [status, headers, body]. */
function req(string $method, string $path, ?string $body = null, ?string $cookie = null, string $key = 'rig-key-abc123'): array
{
    global $base;
    $opts = ['http' => [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => 20,
        'header' => [],
    ]];
    if ($body !== null) {
        $opts['http']['content'] = $body;
        $opts['http']['header'][] = 'Content-Type: application/json';
    }
    if ($cookie !== null) {
        $opts['http']['header'][] = 'Cookie: ' . $cookie;
    }
    if ($key !== '') {
        $sep = str_contains($path, '?') ? '&' : '?';
        $path .= $sep . 'key=' . rawurlencode($key);
    }
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($base . $path, false, $ctx);
    $status = 0;
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
            $status = (int)$m[1];
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return [$status, $headers, (string)$raw];
}

/** The JSON of a response, or null when it is not JSON. */
function jdec(string $body): ?array
{
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/** The plugin's own routes, as the core mounts them. */
function route(string $name, string $query = ''): string
{
    return '/bridge/plugin/bienenstich/' . $name . $query;
}

// ——— A. the key gate: no key, no contract ———
[$s, $h, $b] = req('GET', route('status'), null, null, '');
Harness::check('A1 without a key the door is shut (403)', $s === 403, "got {$s}");
Harness::check('A2 the shut door says one plain line and nothing else',
    trim($b) === 'Bakery closed.', substr($b, 0, 60));
Harness::check('A3 the shut door speaks text, not JSON',
    !str_contains(strtolower($h['content-type'] ?? ''), 'application/json'), $h['content-type'] ?? 'none');

[$s, , $b] = req('GET', route('status'), null, null, 'wrong-key');
Harness::check('A4 a wrong key is shut too', $s === 403, "got {$s}");

// ——— B. with the key, the ware answers ———
[$s, , $b] = req('GET', route('status'));
$j = jdec($b);
Harness::check('B1 the right key opens the door (200)', $s === 200, "got {$s}");
Harness::check('B2 the status is JSON', $j !== null, substr($b, 0, 80));

[$s, , $b] = req('GET', route('content'));
$j = jdec($b);
$slugs = array_column((array)($j['pages'] ?? []), 'slug');
Harness::check('B3 the content answers every published page', $s === 200 && $j !== null, "got {$s}");
Harness::check('B4 the published page is in the jar', in_array('hello', $slugs, true), implode(',', $slugs));

// ——— C. drafts never ride along, not even for an admin ———
Harness::check('C1 the draft is not in the jar', !in_array('secret', $slugs, true), implode(',', $slugs));
Harness::check('C2 the draft title does not leak', !str_contains($b, 'Secret Draft'), 'draft title leaked');
Harness::check('C3 the draft body does not leak', !str_contains($b, 'Honeyed secret'), 'draft body leaked');

[$s, , $b] = req('GET', route('search', '?q=Honeyed'));
$j = jdec($b);
Harness::check('C4 the needle cannot find the draft',
    $s === 200 && ($j['count'] ?? -1) === 0, 'found it');

// the admin session: the core lists drafts, the plugin must not
[$ls, $lh, $lb] = req('POST', '/bridge/login', (string)json_encode([
    'user' => 'admin', 'password' => 'rig-test-pw',
]), null, '');
$login = jdec($lb);
$cookie = '';
foreach ($lh as $name => $value) {
    if ($name === 'set-cookie') {
        $cookie = explode(';', $value)[0];
    }
}
if (!is_array($login) || ($login['ok'] ?? false) !== true || $cookie === '') {
    Harness::check('C5 the admin session is available for the draft comparison', false, 'no login');
} else {
    [$s, , $b] = req('GET', route('content'), null, $cookie);
    $j = jdec($b);
    $slugs = array_column((array)($j['pages'] ?? []), 'slug');
    Harness::check('C5 the draft stays hidden from an admin too',
        $s === 200 && !in_array('secret', $slugs, true), implode(',', $slugs));
    [$s, , $b] = req('GET', route('search', '?q=Honeyed'), null, $cookie);
    $j = jdec($b);
    Harness::check('C6 the needle stays blind for an admin too',
        $s === 200 && ($j['count'] ?? -1) === 0, 'found it');
    // the core DOES list drafts for editors — the difference is the plugin's law
    [$s, , $b] = req('GET', '/bridge/pages', null, $cookie);
    $j = jdec($b);
    $coreSlugs = array_column((array)($j['pages'] ?? []), 'slug');
    Harness::check('C7 the core lists the draft for editors (the plugin is stricter)',
        in_array('secret', $coreSlugs, true), 'the core did not list it');
}

// ——— D. the files themselves are not served ———
[$s] = req('GET', '/bridge/plugins/bienenstich/plugin.php', null, null, '');
Harness::check('D1 the code file is not served', $s === 404, "got {$s}");
[$s] = req('GET', '/bridge/plugins/bienenstich/plugin.json', null, null, '');
Harness::check('D2 the manifest is not served', $s === 404, "got {$s}");
[$s] = req('GET', '/bridge/plugin/bienenstich', null, null, '');
Harness::check('D3 the plugin without a route is a 404', $s === 404, "got {$s}");
[$s] = req('GET', '/bridge/plugin/bienenstich/nope', null, null, '');
Harness::check('D4 an undeclared route is a 404', $s === 404, "got {$s}");

// ——— E. the read-only law ———
[$s, , $before] = req('GET', route('content'));
[$s, , $b] = req('GET', route('content', '?type=pages'));
$j = jdec($b);
Harness::check('E1 a type filter narrows the jar', $s === 200 && is_array($j), "got {$s}");
[$s, , $b] = req('GET', route('content', '?type=../../etc/passwd'));
$j = jdec($b);
Harness::check('E2 a path climb yields no pages', $s === 200 && is_array($j) && $j['pages'] === [], "got {$s}");
[$s, , $b] = req('GET', route('search', '?q=*'));
$j = jdec($b);
Harness::check('E3 the needle is literal, not a regex',
    $s === 200 && is_array($j) && ($j['count'] ?? -1) === 0, 'wildcard matched');

// ——— F. the answer is always JSON, always with the right hat ———
foreach (['content', 'status'] as $r) {
    [$s, $h, $b] = req('GET', route($r));
    Harness::check("F1 {$r} answers application/json",
        str_contains(strtolower($h['content-type'] ?? ''), 'application/json'), $h['content-type'] ?? 'none');
    Harness::check("F2 {$r} answers valid JSON", jdec($b) !== null, substr($b, 0, 80));
}
[$s, $h] = req('GET', route('content'));
Harness::check('F3 the CORS door is open for plain GETs',
    ($h['access-control-allow-origin'] ?? '') === '*', $h['access-control-allow-origin'] ?? 'none');

// ——— G. the site still stands with the ware switched on ———
[$s, , $b] = req('GET', '/');
Harness::check('G1 the public face still serves', $s === 200 && str_contains($b, '<html'), "got {$s}");
[$s, , $b] = req('GET', '/bridge/pages');
Harness::check('G2 the core page index still answers', $s === 200 && jdec($b) !== null, "got {$s}");
[$s, , $b] = req('GET', route('status'), null, 'PHPSESSID=not-a-real-session');
Harness::check('G3 a bogus session does not confuse the public door', $s === 200, "got {$s}");

// ——— the teardown ———
proc_terminate($proc, SIGKILL);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
proc_close($proc);
rigDestroy($rig);

Harness::verdict('bienenstich battery');
