<?php

/**
 * 1. Relative path: index.php
 * 2. Description: Root front controller — the one public entry point.
 *                 /api/* → the JSON core; real public files (themes/, api/assets/)
 *                 pass through untouched; everything else is the public site.
 *                 Doubles as the php -S router script for dev (`php -S localhost:8080 index.php`).
 * 3. Expects: Apache + mod_rewrite with the shipped .htaccess, or php -S with this file.
 * 4. Provides: Clean URLs for the public site and the API alike.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// ——— /api/* always belongs to the JSON core, real file or not ———
if ($path !== '/' && str_starts_with($path, '/api/') && !str_starts_with($path, '/api/assets/')) {
    require __DIR__ . '/api/index.php';
    exit;
}

// ——— real public files: let the server do its job (php -S router mode) ———
// Whitelist only: themes, plugin assets, and the fallback render bundle. Plugin
// CODE is never whitelisted — a direct hit on plugins/<name>/plugin.php would
// execute it outside the front controller. Everything else on disk is none of
// the visitor's business — it falls through to the public 404.
if ($path !== '/') {
    $file = realpath(__DIR__ . rawurldecode($path));
    $root = realpath(__DIR__);
    if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
        if (str_starts_with($rel, 'themes/') || str_starts_with($rel, 'api/assets/')
            || preg_match('#^plugins/[a-z0-9-]+/assets/#', $rel) === 1) {
            if (PHP_SAPI === 'cli-server') {
                return false; // php -S serves it with proper MIME
            }
            // Safety net for exotic SAPIs without rewrite: stream it ourselves.
            $mime = str_ends_with($rel, '.css') ? 'text/css'
                : (str_ends_with($rel, '.js') ? 'text/javascript' : 'application/octet-stream');
            header('Content-Type: ' . $mime);
            header('X-Content-Type-Options: nosniff');
            readfile($file);
            exit;
        }
    }
}

// ——— everything else: the public site ———
require __DIR__ . '/api/serve.php';
\Serve\dispatch($path);
