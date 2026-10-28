<?php

/**
 * 1. Relative path: api/config/accounts.php
 * 2. Description: Account store TEMPLATE. Copy to accounts.local.php (git-ignored) and replace.
 *                 Roles: 'admin' (full control incl. accounts) or 'editor' (content only).
 * 3. Generate:   php -r "echo password_hash('your-login-password', PASSWORD_ARGON2ID), PHP_EOL;"
 *    (params: memory_cost 65536, time_cost 4, threads 1 — keep them if unsure)
 * 4. This template ships with admin/'changeme' — REPLACE before any real deployment.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

return [
    'admin' => [
        'role' => 'admin',
        // Argon2id (m=65536, t=4, p=1) hash of "changeme"
        'passwordHash' => '$argon2id$v=19$m=65536,t=4,p=1$UEY1VzFwV2dOOTB1M0pmQQ$/10G8k3AJJRYJnD/yOVJ8QKdk/pVT1I7NhDewvcGJVw',
    ],
];
