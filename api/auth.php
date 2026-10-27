<?php

/**
 * 1. Relative path: api/auth.php
 * 2. Description: Single-password session auth. Accounts live in config/auth.php;
 *                 the password is stored as an Argon2id hash — never plaintext.
 * 3. Provides: login/check/logout. TOTP optional later.
 * 4. SPDX-License-Identifier: MPL-2.0
 * 5. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Auth;

securityHeaders();
session_start();

function hashPath(): string
{
    return __DIR__ . '/config/auth.php';
}

/** Local, git-ignored override with the real hash wins over the template. */
function configPath(): string
{
    $local = __DIR__ . '/config/auth.local.php';
    return is_file($local) ? $local : hashPath();
}

function securityHeaders(): void
{
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

function login(): void
{
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $password = $body['password'] ?? null;
    if (!is_string($password)) {
        Router\refuse(400, 'Password required.');
    }

    // Exceptions must never leak whether the accounts file is missing — same 401 either way.
    try {
        $accounts = require configPath();
    } catch (\Throwable) {
        Router\refuse(401, 'Invalid credentials.');
    }
    $hash = $accounts['passwordHash'] ?? null;

    if (!is_string($hash) || !password_verify($password, $hash)) {
        usleep(200_000); // cheap brake for online guessing
        Router\refuse(401, 'Invalid credentials.');
    }

    session_regenerate_id(true);
    $_SESSION['schmarrn'] = true;
    $_SESSION['founded'] = time();
}

function check(): bool
{
    return ($_SESSION['schmarrn'] ?? false) === true
        // 24h lifetime — FTP hosts, no cron; the request itself is the clock.
        && (time() - ($_SESSION['founded'] ?? 0)) < 86400;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
