<?php

/**
 * 1. Relative path: api/config/mail.php
 * 2. Description: SMTP config TEMPLATE. Copy to mail.local.php (git-ignored) and fill in.
 * 3. secure: 'tls' (STARTTLS, usual port 587) or 'ssl' (implicit, port 465).
 * 4. Leave host empty in mail.local.php to disable all mail features cleanly.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

return [
    'smtp' => [
        'host' => '',            // e.g. 'mail.example.com' — empty = mail disabled
        'port' => 587,
        'user' => '',
        'pass' => '',
        'secure' => 'tls',
    ],
    'from' => [
        'email' => '',           // e.g. 'schmarrn@example.com'
        'name' => 'Schmarrn',
    ],
];
