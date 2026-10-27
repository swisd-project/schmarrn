<?php

/**
 * 1. Relative path: api/index.php
 * 2. Description: Front controller — the only PHP entry point. Routes /api/*, serves strict JSON.
 * 3. Expects: PHPRouter, a rewrite to this file for /api/* requests (or direct /api/index.php).
 * 4. Provides: Content store CRUD over raw .md files, session auth, mtime-sentinel file cache.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

require __DIR__ . '/router.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/store.php';
require __DIR__ . '/cache.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS: dashboard may run on a dev origin; credentials needed for the session.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = Router\pathAfter('/api/');

try {
    switch (true) {
        case $method === 'POST' && $path === 'login':
            Auth\login();
            echo json_encode(['ok' => true]);
            break;

        case $method === 'POST' && $path === 'logout':
            Auth\logout();
            echo json_encode(['ok' => true]);
            break;

        case $method === 'GET' && $path === 'session':
            echo json_encode(['authenticated' => Auth\check()]);
            break;

        case $method === 'GET' && $path === 'pages':
            // Public listing for strangers (drafts filtered inside), full tree for editors.
            echo json_encode(Store\listAll(), JSON_UNESCAPED_SLASHES);
            break;

        // Route: GET /api/page/{type}/{slug} — raw markdown, body passthrough (not JSON).
        case $method === 'GET' && preg_match('#^page/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            [, $type, $slug] = explode('/', $path);
            $raw = Store\read($type, $slug);
            if ($raw === null) {
                Router\refuse(404, "Page not found: {$type}/{$slug}");
            }
            // Public strangers never taste drafts — but editors do, via the same URL.
            if (Store\isDraft($type, $slug) && !Auth\check()) {
                Router\refuse(404, "Page not found: {$type}/{$slug}");
            }
            if (Cache\isFresh($type, $slug)) {
                header('X-Cache: HIT');
                Cache\emit($type, $slug);
                exit;
            }
            header('X-Cache: MISS');
            header('Content-Type: text/markdown; charset=utf-8');
            Cache\store($type, $slug, $raw);
            echo $raw;
            break;

        // Route: PUT /api/page/{type}/{slug} — atomic write of the whole .md file.
        case $method === 'PUT' && preg_match('#^page/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            [, $type, $slug] = explode('/', $path);
            Store\write($type, $slug, file_get_contents('php://input') ?: '');
            Cache\drop($type, $slug);
            echo json_encode(['ok' => true]);
            break;

        default:
            Router\refuse(404, 'Unknown route.');
    }
} catch (Throwable $e) {
    Router\refuse(500, 'Internal error.');
}
