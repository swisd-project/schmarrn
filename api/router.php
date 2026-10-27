<?php

/**
 * 1. Relative path: api/router.php
 * 2. Description: Tiny routing helpers. No framework — Schmarrn stays shred-thin.
 * 3. Provides: Path parsing, auth failure response, strict JSON error exit.
 * 4. SPDX-License-Identifier: MPL-2.0
 * 5. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Router;

/** Strip a prefix from the request path, normalizing slashes. */
function pathAfter(string $prefix): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $relative = substr($uri, strlen($prefix));
    return trim($relative, '/');
}

/** JSON error response that politely ends the request. */
function refuse(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}
