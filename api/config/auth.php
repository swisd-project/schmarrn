<?php

/**
 * 1. Relative path: api/config/auth.php
 * 2. Description: Auth config TEMPLATE. Copy to auth.local.php and paste your real hash.
 * 3. Generate:  php -r "echo password_hash('your-login-password', PASSWORD_ARGON2ID), PHP_EOL;"
 * 4. auth.local.php is git-ignored; this template ships with a hash of 'changeme'.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

/** Argon2id (m=65536, t=4, p=1) hash of "changeme" — REPLACE before any real deployment. */
return ['passwordHash' => '$argon2id$v=19$m=65536,p=1,t=4$iprGL1iUj1Vno7iq8eOznw$4O8ncQqS8ly6tt+ESyhSLR2rY9hF0ROgtalHblR3gyI'];
