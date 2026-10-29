<?php

/**
 * 1. Relative path: api/auth.php
 * 2. Description: Named-account session auth with roles (admin/editor).
 *                 Hashes live in the account store (accounts.php lib); the session
 *                 carries user + role + CSRF token. 24h lifetime, self-managed.
 * 3. Provides: login/check/logout, user(), role(), isAdmin(), csrf token mint/match,
 *              file-cache login rate limiting, host-proof session directory.
 * 4. SPDX-License-Identifier: MPL-2.0
 * 5. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Auth;

// Library files stand alone: each requires its dependencies explicitly.
require_once __DIR__ . '/router.php';

const MAX_ATTEMPTS = 6;
const ATTEMPT_WINDOW = 600; // s
const LIFETIME = 86400;     // 24 h — FTP hosts, no cron; the request itself is the clock.

// Timing dough for ghost logins: an Argon2id hash of a string nobody owns.
// Verifying against it keeps unknown-username attempts at the same cost as
// real ones — the timing side channel stays shut.
const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$My84OUxGaUJWMElJeTEwMw$J6o5Usn7EslROOYCzE5TqNOVh0P9WcGqUw7DC/l2KIo';

securityHeaders();
hostProofSessionDir();
// Cookie law, explicit — never the host's php.ini lottery: HttpOnly (no JS reads
// the session), SameSite=Lax (cross-site POSTs carry no cookie; CSRF covers the
// rest), Secure the moment the request arrived over HTTPS (dev on plain http
// stays workable).
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
// Strict mode: a caller-supplied PHPSESSID is refused and a fresh one minted.
// Login already regenerates the id (fixation dies there) — this closes the
// window BEFORE login where a crafted-but-unused id would simply be accepted.
ini_set('session.use_strict_mode', '1');
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');
session_start();

function hostProofSessionDir(): void
{
    // Shared hosts GC the system session dir capriciously; own it under our writable tree.
    $dir = __DIR__ . '/cache/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
}

function securityHeaders(): void
{
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

// ——— login rate limiting: one failure ledger per IP, in the file cache ———

function attemptFile(string $ip): string
{
    return __DIR__ . '/cache/ratelimit-' . hash('sha256', $ip) . '.json';
}

/** Blocks with 429 if the IP burned through its attempts recently. Returns failures-so-far. */
function gateLogin(string $ip): int
{
    $ledger = [];
    if (is_file($file = attemptFile($ip))) {
        $ledger = json_decode((string)file_get_contents($file), true) ?: [];
        ($ledger['windowStart'] ?? 0) + ATTEMPT_WINDOW < time() && $ledger = [];
    }
    // ct is cheap; wt is the brake. Both on the house.
    if (($ledger['count'] ?? 0) >= MAX_ATTEMPTS) {
        header('Retry-After: ' . (string)((int)$ledger['windowStart'] + ATTEMPT_WINDOW - time()));
        \Router\refuse(429, 'Too many failed logins. Rest, schmarrn, rest.');
    }
    return (int)($ledger['count'] ?? 0);
}

function countFailure(string $ip): void
{
    $count = gateLogin($ip); // also validates the window
    tick($ip);
    usleep(200_000); // cheap brake for online guessing
}

/** Count one request against the IP ledger (reset-request endpoints tick per visit). */
function tick(string $ip): void
{
    gateLogin($ip); // keep the 429 wall up for ledger-full IPs
    @file_put_contents(attemptFile($ip), json_encode(['count' => gateLogin($ip) + 1, 'windowStart' => time()]));
}

function clearFailures(string $ip): void
{
    @unlink(attemptFile($ip));
}

// ——— session identity ———

function login(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    gateLogin($ip);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $user = is_string($body['user'] ?? null) ? $body['user'] : '';
    $password = is_string($body['password'] ?? null) ? $body['password'] : null;
    if ($user === '' || !is_string($password)) {
        \Router\refuse(400, 'The oven needs both — user and password.');
    }

    // Exceptions must never leak whether the account store is missing — same 401 either way.
    try {
        $account = \Accounts\all()[$user] ?? null;
    } catch (\Throwable) {
        $account = null;
    }

    // Timing dough: an unknown user must cost the same oven time as a real one,
    // or the verify-skip betrays which usernames exist. Verify against a dummy
    // hash of the same recipe (Argon2id) when the account is a ghost.
    if ($account === null) {
        password_verify($password, DUMMY_HASH);
    }

    if ($account === null || !password_verify($password, $account['passwordHash'])) {
        countFailure($ip);
        \Router\refuse(401, 'Wrong recipe — that user/password pair does not bake.');
    }

    clearFailures($ip);
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['epoch'] = $account['epoch'] ?? 0;
    $_SESSION['founded'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * Authenticated within the 24h window — AND the account still exists with the
 * session's epoch. The session proves a login happened once; the store proves
 * it still counts. Deletions and role changes take effect on the next request
 * (no 24h revocation lag), and a password change/reset bumps the account's
 * epoch so every older session — a hijacked one included — dies with it.
 */
function check(): bool
{
    $user = $_SESSION['user'] ?? null;
    if (!is_string($user) || (time() - ($_SESSION['founded'] ?? 0)) >= LIFETIME) {
        return false;
    }
    $account = record($user);
    if ($account === null || ($_SESSION['epoch'] ?? 0) !== ($account['epoch'] ?? 0)) {
        return false;
    }
    return true;
}

function user(): string
{
    return check() ? (string)$_SESSION['user'] : '';
}

/** The role comes from the store, fresh per request — never from the session. */
function role(): string
{
    if (!check()) {
        return '';
    }
    return (string)(record((string)$_SESSION['user'])['role'] ?? '');
}

/** The account record behind a session user, or null when it's a ghost. */
function record(string $user): ?array
{
    try {
        $account = \Accounts\all()[$user] ?? null;
    } catch (\Throwable) {
        return null;
    }
    return is_array($account) ? $account : null;
}

function isAdmin(): bool
{
    return role() === 'admin';
}

/** Mutating routes call this right after the auth check. */
function assertCsrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$sent)) {
        \Router\refuse(403, 'That CSRF token is from a different bakery.');
    }
}

function csrf(): string
{
    return (string)($_SESSION['csrf'] ?? '');
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
