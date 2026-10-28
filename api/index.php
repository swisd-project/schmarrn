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
require __DIR__ . '/mail.php';
require __DIR__ . '/lock.php';
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

        // ——— password flows ———

        // POST /api/me/password — self-service change (authed + knows current).
        case $method === 'POST' && $path === 'me/password':
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $current = is_string($body['current'] ?? null) ? $body['current'] : '';
            $fresh = is_string($body['password'] ?? null) ? $body['password'] : '';
            $record = Accounts\all()[Auth\user()] ?? null;
            if ($record === null || !password_verify($current, $record['passwordHash'])) {
                Router\refuse(401, 'Current password is wrong.');
            }
            if (strlen($fresh) < 8) {
                Router\refuse(400, 'Password must be at least 8 characters.');
            }
            Accounts\setHash(Auth\user(), password_hash($fresh, PASSWORD_ARGON2ID));
            echo json_encode(['ok' => true]);
            break;

        // POST /api/account/<user>/invite — admin (re)issues credentials by mail.
        case $method === 'POST' && preg_match('#^account/[a-z0-9][a-z0-9._-]{0,63}/invite$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only.');
            }
            Auth\assertCsrf();
            $target = substr($path, strlen('account/'), -7);
            $record = Accounts\all()[$target] ?? null;
            if ($record === null || ($record['email'] ?? '') === '') {
                Router\refuse(409, "No account with an email: {$target}");
            }
            $temp = bin2hex(random_bytes(9));
            // Mail flies BEFORE the hash lands — mail failure then can't strand a muted account.
            \Mail\send((string)$record['email'], 'Your Schmarrn login',
                "A Schmarrn account for '{$target}' was (re)issued.\n\n"
                . "User:     {$target}\n"
                . "Password: {$temp}\n\n"
                . "Sign in and change this password promptly under me/password.");
            Accounts\setHash($target, password_hash($temp, PASSWORD_ARGON2ID));
            echo json_encode(['ok' => true]);
            break;

        // POST /api/reset-request — anonymous self-reset door. Enumeration-proof, rate-limited.
        case $method === 'POST' && $path === 'reset-request':
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            Auth\gateLogin($ip);
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $user = is_string($body['user'] ?? null) ? $body['user'] : '';
            if ($user !== '') {
                $record = Accounts\all()[$user] ?? null;
                if ($record !== null && ($record['email'] ?? '') !== '' && \Mail\enabled()) {
                    Accounts\issueMailToken($user, (string)$record['email']);
                } else {
                    @error_log('[schmarrn-reset] request for unknown/unmailable/no-smtp user, ip logged');
                }
            }
            Auth\tick($ip);
            echo json_encode(['ok' => true, 'message' => 'If the account exists and can be mailed, a token is on its way.']);
            break;

        // POST /api/reset-confirm — anonymous: one-time token + new password.
        case $method === 'POST' && $path === 'reset-confirm':
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            Auth\gateLogin($ip);
            Auth\tick($ip);
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $user = is_string($body['user'] ?? null) ? $body['user'] : '';
            $token = is_string($body['token'] ?? null) ? $body['token'] : '';
            $fresh = is_string($body['password'] ?? null) ? $body['password'] : '';
            if ($user === '' || $token === '') {
                Router\refuse(400, 'User and token required.');
            }
            if (strlen($fresh) < 8) {
                Router\refuse(400, 'Password must be at least 8 characters.');
            }
            Accounts\takeMailToken($user, $token);
            Accounts\setHash($user, password_hash($fresh, PASSWORD_ARGON2ID));
            echo json_encode(['ok' => true]);
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
                fn($user, $r) => ['user' => $user, 'role' => $r['role'], 'email' => $r['email'] ?? ''],
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
            $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
            if (strlen($password) < 8) {
                Router\refuse(400, 'Password must be at least 8 characters.');
            }
            Accounts\add($user, password_hash($password, PASSWORD_ARGON2ID), $role, $email);
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
            if (is_string($body['email'] ?? null)) {
                Accounts\setEmail($target, trim($body['email']));
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

        // ——— edit locks: heartbeat in warm, silence and polite exit free ———
        case $method === 'PUT' && preg_match('#^lock/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            Lock\acquire($type, $slug, Auth\user(), ($body['force'] ?? null) === true);
            echo json_encode(Lock\peek($type, $slug));
            break;
        case $method === 'GET' && preg_match('#^lock/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            [, $type, $slug] = explode('/', $path);
            echo json_encode(Lock\peek($type, $slug));
            break;
        case $method === 'DELETE' && preg_match('#^lock/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            Lock\release($type, $slug, Auth\user());
            echo json_encode(['ok' => true]);
            break;


        // ——— page-local media: upload via modal, serve raw, immutable by law ———
        case $method === 'GET' && preg_match('#^media/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            [, $type, $slug] = explode('/', $path);
            echo json_encode(Store\mediaList($type, $slug), JSON_UNESCAPED_SLASHES);
            break;
        case $method === 'POST' && preg_match('#^media/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            $upload = $_FILES['file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name']) || !is_string($upload['name'])) {
                Router\refuse(400, 'No file uploaded.');
            }
            $stored = Store\mediaStore($type, $slug, $upload['name'], $upload['tmp_name']);
            http_response_code(201);
            echo json_encode($stored, JSON_UNESCAPED_SLASHES);
            break;
        case $method === 'GET' && preg_match('#^media/[a-z0-9-]+/[a-z0-9-]+/[A-Za-z0-9._-]+$#', $path) === 1:
            [, $type, $slug, $name] = explode('/', $path);
            $path = Store\mediaPath($type, $slug, (string)$name);
            if ($path === null) {
                Router\refuse(404, 'No such media.');
            }
            header('Content-Type: ' . Store\mediaMime((string)$name));
            header('Content-Length: ' . (string)filesize($path));
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;

        // ——— editorial drafts + publishing: the editor's dirty little secret ———

        // GET — editorial copy (sidecar or published file, whichever freshest in life).
        case $method === 'GET' && preg_match('#^draft/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            [, $type, $slug] = explode('/', $path);
            $raw = Store\readDraft($type, $slug);
            if ($raw === null) {
                Router\refuse(404, "Page not found: {$type}/{$slug}");
            }
            header('Content-Type: text/markdown; charset=utf-8');
            header('X-Has-Draft: ' . (Store\hasDraft($type, $slug) ? '1' : '0'));
            echo $raw;
            break;

        // PUT — autosave-to-draft lives here. Lock-gated like page writes.
        case $method === 'PUT' && preg_match('#^draft/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            Lock\assertFreeOrMine($type, $slug, Auth\user());
            Store\writeDraft($type, $slug, file_get_contents('php://input') ?: '');
            Lock\acquire($type, $slug, Auth\user());
            echo json_encode(['ok' => true]);
            break;

        // POST — `draft/<t>/<s>/publish` and `/unpublish`. The button behind the glass.
        case $method === 'POST' && preg_match('#^draft/[a-z0-9-]+/[a-z0-9-]+/(publish|unpublish)$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'Not authenticated.');
            }
            Auth\assertCsrf();
            [, $type, $slug, $verb] = explode('/', $path);
            Lock\assertFreeOrMine($type, $slug, Auth\user());
            if (is_string($verb) && $verb === 'publish') {
                Store\publish($type, $slug);
                Cache\drop($type, $slug);
                Lock\acquire($type, $slug, Auth\user());
                echo json_encode(['ok' => true, 'published' => true]);
            } else {
                Store\unpublish($type, $slug);
                Cache\drop($type, $slug);
                echo json_encode(['ok' => true, 'published' => false]);
            }
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
            Lock\assertFreeOrMine($type, $slug, Auth\user());
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
            Lock\assertFreeOrMine($type, $slug, Auth\user());
            Store\write($type, $slug, file_get_contents('php://input') ?: '');
            Cache\drop($type, $slug);
            Lock\acquire($type, $slug, Auth\user()); // writer owns the lock — kitchen etiquette
            echo json_encode(['ok' => true]);
            break;

        default:
            Router\refuse(404, 'Unknown route.');
    }
} catch (Throwable $e) {
    @error_log('[schmarrn-500] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Router\refuse(500, 'Internal error.');
}
