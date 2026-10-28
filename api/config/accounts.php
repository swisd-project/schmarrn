<?php

/**
 * 1. Relative path: api/config/accounts.php
 * 2. Description: Account store TEMPLATE — ships EMPTY on purpose. A fresh install
 *                 has no known credentials: the first-visit setup wizard creates
 *                 the first admin (and the brand settings) and writes the real
 *                 store to accounts.local.php (git-ignored).
 * 3. Roles: 'admin' (full control incl. accounts) or 'editor' (content only).
 * 4. Manual alternative: copy this file to accounts.local.php and mint a hash —
 *    php -r "echo password_hash('your-login-password', PASSWORD_ARGON2ID), PHP_EOL;"
 *    (params: memory_cost 65536, time_cost 4, threads 1 — keep them if unsure)
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

return [];
