<?php

/**
 * 1. Relative path: tests/refusal-battery.php
 * 2. Description: The REFUSAL battery — every domain refusal the core can
 *                 answer, pinned to its exact status and body. This is the
 *                 proof for STEP 2 (the Domain\Error refactor): when the
 *                 domain stops calling Router\refuse() and starts throwing,
 *                 every one of these answers must stay byte-identical.
 * 3. Expects: The repo root as argv[1]. A rig is built from the current tree
 *                 (tests/rig.php) and served on a battery port.
 * 4. Provides: PASS/FAIL per case, a count, exit 1 on any failure.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/rig.php';

$root = dirname(__DIR__);
$rig = rigBuild($root);

$port = 8196;
for ($p = 8196; $p < 8300; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:{$p}", $errno, $errstr);
    if ($sock !== false) {
        fclose($sock);
        $port = $p;
        break;
    }
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
    rigDestroy($rig);
    exit(2);
}

/** One request: [status, body, headers]. */
function req(string $method, string $path, ?string $body = null, ?string $cookie = null, ?string $csrf = null, array $extra = []): array
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
    if ($csrf !== null) {
        $opts['http']['header'][] = 'X-CSRF-Token: ' . $csrf;
    }
    foreach ($extra as $name => $value) {
        $opts['http']['header'][] = "{$name}: {$value}";
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
    return [$status, (string)$raw, $headers];
}

/** The refusal's message — the JSON's 'error' field. */
function errOf(string $body): string
{
    $j = json_decode($body, true);
    return is_array($j) && is_string($j['error'] ?? null) ? $j['error'] : '';
}

/** A refusal case: the status and the message must both be exactly this. */
function refusal(string $name, int $status, string $message, array $r): void
{
    [$got, $body] = $r;
    $gotMessage = errOf($body);
    if ($got !== $status) {
        Harness::check($name, false, "status {$status} expected, got {$got} ({$body})");
        return;
    }
    if ($gotMessage !== $message) {
        Harness::check($name, false, "message mismatch: got '{$gotMessage}'");
        return;
    }
    Harness::check($name, true);
}

// ——— the login ———
// ONE login: the CSRF token belongs to the session that made it.
[$s, $lb, $h] = req('POST', '/bridge/login', (string)json_encode([
    'user' => 'admin', 'password' => 'rig-test-pw',
]));
$cookie = '';
foreach ($h as $name => $value) {
    if ($name === 'set-cookie') {
        $cookie = explode(';', $value)[0];
    }
}
$login = json_decode($lb, true);
$csrf = is_array($login) && is_string($login['csrf'] ?? null) ? $login['csrf'] : '';
if ($cookie === '' || $csrf === '') {
    Harness::check('the admin session is available for the refusal cases', false, 'no login');
    proc_terminate($proc, SIGKILL);
    rigDestroy($rig);
    exit(2);
}

// ——— A. the account domain ———
refusal('A1 an unknown account invite is 409', 409, 'No account with an email: ghost',
    req('POST', '/bridge/account/ghost/invite', '{}', $cookie, $csrf));

refusal('A2 an unknown account update is 404', 404, 'No account: ghost',
    req('PUT', '/bridge/account/ghost', '{}', $cookie, $csrf));

refusal('A3 a thin password is 400', 400, 'That password is too thin to fold — give it 8+ characters.',
    req('POST', '/bridge/accounts', (string)json_encode([
        'user' => 'someone', 'password' => 'short', 'role' => 'editor',
    ]), $cookie, $csrf));

refusal('A4 an unknown role is 400', 400, 'Unknown role: wizard',
    req('POST', '/bridge/accounts', (string)json_encode([
        'user' => 'someone', 'password' => 'longenough', 'role' => 'wizard',
    ]), $cookie, $csrf));

refusal('A5 the baker cannot eat their own chair', 409,
    'You cannot eat your own chair — that account sits behind this session.',
    req('DELETE', '/bridge/account/admin', null, $cookie, $csrf));

// ——— B. the site domain ———
refusal('B1 a nameless site is 400', 400, 'Every bakery needs a name — site name required.',
    req('PUT', '/bridge/site', (string)json_encode(['name' => '']), $cookie, $csrf));

refusal('B2 an unknown theme is 400', 400, 'Unknown theme: ghost',
    req('PUT', '/bridge/site', (string)json_encode(['name' => 'Rig', 'theme' => 'ghost']), $cookie, $csrf));

refusal('B3 a home slug without a shape is 400', 400, 'The home slice needs a slug — or an empty plate.',
    req('PUT', '/bridge/site', (string)json_encode(['name' => 'Rig', 'home' => 'Not A Slug']), $cookie, $csrf));

// ——— C. the page domain ———
refusal('C1 a page that exists is 409', 409, 'Page already exists: pages/hello',
    req('POST', '/bridge/page/pages/hello', "---\ntitle: x\nslug: hello\n---\n\nbody", $cookie, $csrf));

refusal('C2 the home page stands', 409, 'The home page stands — the index page cannot be deleted.',
    req('DELETE', '/bridge/page/pages/hello', null, $cookie, $csrf));

refusal('C3 a page that is not there is 404', 404, 'Page not found: pages/ghost',
    req('PUT', '/bridge/draft/pages/ghost', "---\ntitle: x\nslug: ghost\n---\n\nbody", $cookie, $csrf));

// C4 — THE LAW THE OLD CODE NEVER ENFORCED, now enforced (STEP 5): the
// frontmatter's own slug key must agree with the URL. The old gate read an
// HTML COMMENT (<!-- slug: ... -->) and never the key, so slug: other rode
// in at /pages/hello unmolested. The gate lives in Meta\gate().
refusal('C4 a slug that does not match the file is 400', 400,
    "The frontmatter breaks its contract: the frontmatter's slug (other) disagrees with the URL (hello).",
    req('PUT', '/bridge/draft/pages/hello',
        "---\ntitle: x\nslug: other\n---\n\nbody", $cookie, $csrf));

refusal('C5 an empty page is 400', 400, 'An empty schmarrn is just a sad pan — write something first.',
    req('PUT', '/bridge/draft/pages/hello', '   ', $cookie, $csrf));

// C6 — the 409 conflict carries the X-Current-Hash header: the domain
// carries the FACT, the transport applies it as a header. (STEP 2's
// headers payload — the byte-identity proof for it.)
[$s, , $h] = req('PUT', '/bridge/draft/pages/hello', "---\ntitle: x\nslug: hello\n---\n\nbody", $cookie, $csrf, ['X-Base-Hash' => 'not-the-real-hash']);
$hash = $h['x-current-hash'] ?? '';
Harness::check('C6 a stale save is refused 409', $s === 409, "got {$s}");
Harness::check('C7 the 409 carries X-Current-Hash (the domain\'s fact, the transport\'s header)',
    $hash !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) === 1, substr($hash, 0, 20));

// ——— D. the auth domain ———
refusal('D1 a wrong current password is 401', 401, 'Current password is wrong.',
    req('POST', '/bridge/me/password', (string)json_encode([
        'current' => 'not-the-password', 'password' => 'longenough',
    ]), $cookie, $csrf));

refusal('D2 a thin new password is 400', 400, 'That password is too thin to fold — give it 8+ characters.',
    req('POST', '/bridge/me/password', (string)json_encode([
        'current' => 'rig-test-pw', 'password' => 'thin',
    ]), $cookie, $csrf));

refusal('D3 a login without a password is 400', 400, 'The oven needs both — user and password.',
    req('POST', '/bridge/login', (string)json_encode(['user' => 'admin'])));

refusal('D4 a login with a wrong password is 401', 401, 'Wrong recipe — that user/password pair does not bake.',
    req('POST', '/bridge/login', (string)json_encode([
        'user' => 'admin', 'password' => 'not-the-password',
    ])));

// ——— E. the wares domain ———
refusal('E1 an unbaked ware name is 400', 400, 'That ware name is unbaked dough.',
    req('POST', '/bridge/wares/install', (string)json_encode(['name' => 'Not A Slug']), $cookie, $csrf));

refusal('E2 a ware that is not on the shelf is 404', 404, "'ghost' is not on the Vitrine's shelf.",
    req('POST', '/bridge/wares/install', (string)json_encode(['name' => 'ghost']), $cookie, $csrf));

// ——— F. the plugin domain ———
refusal('F1 a plugin map that is not a map is 400', 400, 'That plugin map is unbaked dough — send a proper enabled map.',
    req('PUT', '/bridge/plugins', (string)json_encode(['enabled' => 'not-a-map']), $cookie, $csrf));

refusal('F2 an undeclared plugin route is 404', 404, 'That plugin serves nothing — no such route.',
    req('GET', '/bridge/plugin/hello/no-such-route', null, $cookie, $csrf));

refusal('F3 a plugin that is not installed is 404', 404, 'That plugin serves nothing — no such route.',
    req('GET', '/bridge/plugin/no-such-plugin/status', null, $cookie, $csrf));

// ——— the teardown ———
proc_terminate($proc, SIGKILL);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
proc_close($proc);
rigDestroy($rig);

Harness::verdict('refusal battery');
