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

securityHeaders();
hostProofSessionDir();
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
        \Router\refuse(400, 'User and password required.');
    }

    // Exceptions must never leak whether the account store is missing — same 401 either way.
    try {
        $account = \Accounts\all()[$user] ?? null;
    } catch (\Throwable) {
        $account = null;
    }

    if ($account === null || !password_verify($password, $account['passwordHash'])) {
        countFailure($ip);
        \Router\refuse(401, 'Invalid credentials.');
    }

    clearFailures($ip);
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['role'] = $account['role'];
    $_SESSION['founded'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/** Authenticated within the 24h window. */
function check(): bool
{
    return is_string($_SESSION['user'] ?? null)
        && (time() - ($_SESSION['founded'] ?? 0)) < LIFETIME;
}

function user(): string
{
    return check() ? (string)$_SESSION['user'] : '';
}

function role(): string
{
    return check() ? (string)$_SESSION['role'] : '';
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
        \Router\refuse(403, 'CSRF token mismatch.');
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
