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
require __DIR__ . '/accounts.php';
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
    header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
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
            // First-class CSRF receipt, right off the login counter.
            echo json_encode(['ok' => true, 'user' => Auth\user(), 'role' => Auth\role(), 'csrf' => Auth\csrf()]);
            break;

        case $method === 'POST' && $path === 'logout':
            Auth\assertCsrf();
            Auth\logout();
            echo json_encode(['ok' => true]);
            break;

        case $method === 'GET' && $path === 'session':
            echo json_encode([
                'authenticated' => Auth\check(),
                'user' => Auth\user(),
                'role' => Auth\role(),
                'csrf' => Auth\csrf(),
            ]);
            break;

        // ——— account management: admin-only, CSRF-gated ———
        case $method === 'GET' && $path === 'accounts':
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only.');
            }
            $accounts = Accounts\all();
            echo json_encode(array_map(
                fn($user, $r) => ['user' => $user, 'role' => $r['role']],
                array_keys($accounts),
                $accounts
            ));
            break;

        case $method === 'POST' && $path === 'accounts':
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $user = is_string($body['user'] ?? null) ? $body['user'] : '';
            $password = is_string($body['password'] ?? null) ? $body['password'] : '';
            $role = is_string($body['role'] ?? null) ? $body['role'] : 'editor';
            if (strlen($password) < 8) {
                Router\refuse(400, 'Password must be at least 8 characters.');
            }
            Accounts\add($user, password_hash($password, PASSWORD_ARGON2ID), $role);
            http_response_code(201);
            echo json_encode(['ok' => true]);
            break;

        case $method === 'PUT' && preg_match('#^account/[a-z0-9][a-z0-9._-]{0,63}$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only.');
            }
            Auth\assertCsrf();
            $target = substr($path, strlen('account/'));
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $accounts = Accounts\all();
            if (!isset($accounts[$target])) {
                Router\refuse(404, "No account: {$target}");
            }
            // Never leave the bakery without an admin — demote/demolish the last one and the door locks.
            $roles = isset($body['role']) && is_string($body['role']) ? $body['role'] : null;
            if ($roles !== null && $roles !== 'admin' && $accounts[$target]['role'] === 'admin'
                && count(array_filter($accounts, fn($r) => $r['role'] === 'admin')) === 1) {
                Router\refuse(409, 'Refusing to demote the last admin.');
            }
            if ($roles !== null) {
                Accounts\setRole($target, $roles);
            }
            if (is_string($body['password'] ?? null)) {
                if (strlen($body['password']) < 8) {
                    Router\refuse(400, 'Password must be at least 8 characters.');
                }
                Accounts\setHash($target, password_hash($body['password'], PASSWORD_ARGON2ID));
            }
            echo json_encode(['ok' => true]);
            break;

        case $method === 'DELETE' && preg_match('#^account/[a-z0-9][a-z0-9._-]{0,63}$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only.');
            }
            Auth\assertCsrf();
            $target = substr($path, strlen('account/'));
            $accounts = Accounts\all();
            if ($target === Auth\user()) {
                Router\refuse(409, 'Refusing to delete the account behind this session.');
            }
            if (!isset($accounts[$target])) {
                Router\refuse(404, "No account: {$target}");
            }
            if ($accounts[$target]['role'] === 'admin'
                && count(array_filter($accounts, fn($r) => $r['role'] === 'admin')) === 1) {
                Router\refuse(409, 'Refusing to delete the last admin.');
            }
            Accounts\remove($target);
            echo json_encode(['ok' => true]);
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

        // Route: POST /api/page/{type}/{slug} — create (unfold) a fresh page; 409 if it exists.
        case $method === 'POST' && preg_match('#^page/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            if (Store\read($type, $slug) !== null) {
                Router\refuse(409, "Page already exists: {$type}/{$slug}");
            }
            Store\write($type, $slug, file_get_contents('php://input') ?: '');
            Cache\drop($type, $slug);
            http_response_code(201);
            echo json_encode(['ok' => true]);
            break;
        case $method === 'PUT' && preg_match('#^page/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            Auth\assertCsrf();
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
