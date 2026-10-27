<?php

/**
 * 1. Relative path: api/config/auth.php
 * 2. Description: Auth config TEMPLATE. Copy to auth.local.php and paste your real hash.
 * 3. Generate:  php -r "echo password_hash('your-login-password', PASSWORD_ARGON2ID), PHP_EOL;"
 *    (params: memory_cost 65536, time_cost 4, threads 1 — keep them if unsure)
 * 4. auth.local.php is git-ignored; this template ships with a hash of 'changeme'.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/** Argon2id (m=65536, t=4, p=1) hash of "changeme" — REPLACE before any real deployment. */
return ['passwordHash' => '$argon2id$v=19$m=65536,t=4,p=1$UEY1VzFwV2dOOTB1M0pmQQ$/10G8k3AJJRYJnD/yOVJ8QKdk/pVT1I7NhDewvcGJVw'];