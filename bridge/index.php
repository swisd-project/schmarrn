<?php

/**
 * 1. Relative path: bridge/index.php
 * 2. Description: Front controller — the only PHP entry point. Routes /bridge/*, serves strict JSON.
 * 3. Expects: PHPRouter, a rewrite to this file for /bridge/* requests (or direct /bridge/index.php).
 * 4. Provides: Content store CRUD over raw .md files, session auth, mtime-sentinel file cache.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

// The floor: Argon2id, str_starts_with, arrow functions. Refuse to run half-alive.
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Schmarrn needs PHP 8.1 or newer.');
}

// Paths and stack traces are for the operator's eyes, not the internet's.
// Local dev (the built-in server on loopback) keeps its errors.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

require_once __DIR__ . '/router.php';

/** The optional media sub-folder — a dedicated editor's home, always validated. */
function mediaFolderParam(): string
{
    $folder = is_string($_GET['folder'] ?? null) ? $_GET['folder'] : '';
    return preg_match('/^[a-z0-9][a-z0-9-]*$/', $folder) === 1 ? $folder : '';
}
require_once __DIR__ . '/auth.php';      // pulls accounts.php + router.php itself now
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/plugins.php';
require_once __DIR__ . '/wares.php';
require_once __DIR__ . '/core.php'; // the core's self-update (GitHub versions)
require_once __DIR__ . '/github.php'; // the GitHub door (private repos as ware sources)
require_once __DIR__ . '/serve.php'; // public JSON API reuses the serving engine's byte-peeks

/**
 * The editor leash: published HTML is CLIENT-rendered and stored as-is, so
 * whatever an account sends becomes the page every visitor executes. A legit
 * editor render can never contain executable HTML (the module wall + the URL
 * gate strip it at the source) — so anything executable that arrives is an
 * editor trying to script the admin's browser. Editors get those parts cut;
 * admins publish raw (they own the site and its HTML by definition).
 * Cut, not reject: the rest of the editor's page is honest work.
 */
function stripExecutableHtml(string $html): string
{
    // <script> blocks — with or without a closing tag (an unclosed opener
    // would swallow the rest of the page into script-land in the browser).
    $html = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $html) ?? '';
    $html = preg_replace('#</?script\b[^>]*>#i', '', $html) ?? '';
    // Inline event handlers: onclick=, onerror=, onload=, … (word-boundary
    // keeps prose like "season=" intact).
    $html = preg_replace('#\s\bon[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
    // srcdoc hands an iframe a whole executable document of its own.
    $html = preg_replace('#\s\bsrcdoc\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html) ?? '';
    // Script-bearing URLs in the classic attribute slots — the URL gate
    // already guarantees these never come from a legit render.
    $html = preg_replace('#\s(?:href|src|data|poster|action|xlink:href)\s*=\s*(["\'])\s*(?:javascript|vbscript|data\s*:\s*text\s*/\s*html)[^"\']*\1#i', '', $html) ?? '';
    return $html;
}

$path = Router\pathAfter('/bridge/');
$isPublicApi = str_starts_with($path, 'public/');

// ——— the blanko window, from the API head too (Apache bridges straight in
// here): the operation itself ('core/…') stays reachable, everything else is
// told the knife is out. The public plate is served by the root head. ———
if (is_file(__DIR__ . '/cache/core-blank')) {
    require_once __DIR__ . '/core.php';
    // the marker's TTL answers here too (audit U5) — a stale marker is swept
    // at the read and the API walks on
    if (\Core\blankKnives() && !str_starts_with($path, 'core')) {
        Router\refuse(503, \Core\blankoNote());
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS, scoped by audience: the read-only public API is anonymous data — it
// answers to any origin and never to credentials. The private API reflects an
// origin ONLY when it names the same host as the request (the dev dashboard on
// its own port); reflecting arbitrary origins with credentials would hand any
// website a cross-origin read of the session API. SameSite=Lax already keeps
// cross-site fetches cookieless in modern browsers — this closes the rest.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    if ($isPublicApi) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
    } else {
        $originHost = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
        $requestHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
        if ($originHost !== '' && $originHost === $requestHost) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}


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

        // POST /bridge/me/password — self-service change (authed + knows current).
        case $method === 'POST' && $path === 'me/password':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $current = is_string($body['current'] ?? null) ? $body['current'] : '';
            $fresh = is_string($body['password'] ?? null) ? $body['password'] : '';
            // Captured BEFORE setHash: after the epoch bump, Auth\user()'s own
            // check() would fail against the new epoch and return ''.
            $user = Auth\user();
            $record = Accounts\all()[$user] ?? null;
            if ($record === null || !password_verify($current, $record['passwordHash'])) {
                Router\refuse(401, 'Current password is wrong.');
            }
            if (strlen($fresh) < 8) {
                Router\refuse(400, 'That password is too thin to fold — give it 8+ characters.');
            }
            Accounts\setHash($user, password_hash($fresh, PASSWORD_ARGON2ID));
            // The epoch bump above killed every old session of this account —
            // re-stamp THIS one, or the password-changer logs themselves out.
            $_SESSION['epoch'] = Accounts\all()[$user]['epoch'] ?? 0;
            echo json_encode(['ok' => true]);
            break;

        // POST /bridge/account/<user>/invite — admin (re)issues credentials by mail.
        case $method === 'POST' && preg_match('#^account/[a-z0-9][a-z0-9._-]{0,63}/invite$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
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

        // POST /bridge/reset-request — anonymous self-reset door. Enumeration-proof, rate-limited.
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

        // POST /bridge/reset-confirm — anonymous: one-time token + new password.
        case $method === 'POST' && $path === 'reset-confirm':
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            Auth\gateLogin($ip);
            Auth\tick($ip);
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $user = is_string($body['user'] ?? null) ? $body['user'] : '';
            $token = is_string($body['token'] ?? null) ? $body['token'] : '';
            $fresh = is_string($body['password'] ?? null) ? $body['password'] : '';
            if ($user === '' || $token === '') {
                Router\refuse(400, 'User and token, please — the recipe has two ingredients.');
            }
            if (strlen($fresh) < 8) {
                Router\refuse(400, 'That password is too thin to fold — give it 8+ characters.');
            }
            Accounts\takeMailToken($user, $token);
            Accounts\setHash($user, password_hash($fresh, PASSWORD_ARGON2ID));
            echo json_encode(['ok' => true]);
            break;

        // ——— first-visit setup: the wizard's two doors ———

        // Route: GET /bridge/setup/status — public.needed = no accounts exist yet;
        // themes = the theme contract's installed wares (dir with a theme.json).
        case $method === 'GET' && $path === 'setup/status':
            try {
                $needed = count(Accounts\all()) === 0;
            } catch (\Throwable) {
                $needed = true; // no account store = nothing set up yet
            }
            $themes = [];
            foreach (glob(__DIR__ . '/templates/*', GLOB_ONLYDIR) ?: [] as $themeDir) {
                $themeName = basename($themeDir);
                if (preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $themeName) === 1 && is_file($themeDir . '/theme.json')) {
                    $themes[] = $themeName;
                }
            }
            echo json_encode(['needed' => $needed, 'themes' => $themes]);
            break;

        // Route: POST /bridge/setup — public, but only while the bakery has no accounts.
        // Creates the first admin, writes brand settings, and logs the founder in
        // (Auth\login() re-reads the same user/password keys from the body).
        case $method === 'POST' && $path === 'setup':
            // Setup is check-then-act ("no accounts yet" → mint the admin), so
            // on a multi-worker host two simultaneous first-setup requests could
            // both pass the check and both mint an admin. The claim lock makes it
            // single-file: the second arrival waits, re-checks, sees the oven
            // hot, and gets the same 403 as everyone after. Released on request
            // shutdown even through refuse()'s exit.
            $setupLock = @fopen(__DIR__ . '/cache/setup.lock', 'c');
            if ($setupLock === false || !flock($setupLock, LOCK_EX)) {
                Router\refuse(500, 'The kitchen ticket rail jammed — lock write failed.');
            }
            try {
                $needed = count(Accounts\all()) === 0;
            } catch (\Throwable) {
                $needed = true;
            }
            if (!$needed) {
                Router\refuse(403, 'The oven is already hot — setup only fires once.');
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $user = is_string($body['user'] ?? null) ? trim($body['user']) : '';
            $password = is_string($body['password'] ?? null) ? $body['password'] : '';
            $siteName = is_string($body['siteName'] ?? null) ? trim($body['siteName']) : '';
            $claim = is_string($body['claim'] ?? null) ? trim($body['claim']) : '';
            $theme = is_string($body['theme'] ?? null) ? $body['theme'] : 'bakery';
            if ($user === '' || $siteName === '' || strlen($password) < 8) {
                Router\refuse(400, 'Setup needs the full batter: user, site name and a password of 8+ characters.');
            }
            if (preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $theme) !== 1
                || !is_file(__DIR__ . '/templates/' . $theme . '/theme.json')) {
                Router\refuse(400, "Unknown theme: {$theme}");
            }
            Accounts\add($user, password_hash($password, PASSWORD_ARGON2ID), 'admin');
            Site\save(['name' => $siteName, 'claim' => $claim, 'logo' => '', 'theme' => $theme, 'home' => '']);
            \Wares\seedBlanko($theme); // the blanko hello-world: a fresh site is born dressed, never a 404
            Auth\login();
            flock($setupLock, LOCK_UN);
            fclose($setupLock);
            echo json_encode(['ok' => true, 'user' => Auth\user(), 'role' => Auth\role(), 'csrf' => Auth\csrf()]);
            break;

        // ——— brand settings: admin-only read/write over Site\ ———
        case $method === 'GET' && $path === 'site':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            $settings = Site\load();
            // The theme handles ride along: the active theme's declared knobs
            // with their effective value (the stored override, else the default).
            $settings['handles'] = \Serve\handlesFor($settings);
            echo json_encode($settings);
            break;

        case $method === 'PUT' && $path === 'site':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $settings = Site\load();
            foreach (\Site\KEYS as $key) {
                if (is_string($body[$key] ?? null)) {
                    $settings[$key] = trim($body[$key]);
                }
            }
            if ($settings['name'] === '') {
                Router\refuse(400, 'Every bakery needs a name — site name required.');
            }
            if (preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $settings['theme']) !== 1
                || !is_file(__DIR__ . '/templates/' . $settings['theme'] . '/theme.json')) {
                Router\refuse(400, "Unknown theme: {$settings['theme']}");
            }
            if ($settings['home'] !== '' && preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $settings['home']) !== 1) {
                Router\refuse(400, 'The home slice needs a slug — or an empty plate.');
            }
            // ——— the theme handles: a PATCH over the active theme's stored
            // ——— overrides. A value sets a handle, an EMPTY value resets it
            // ——— (back to the theme's default), an absent handle stands.
            // ——— Only DECLARED handles are honored; the theme's word is the
            // ——— contract. ———
            $handles = $body['handles'] ?? null;
            if (is_array($handles)) {
                $stored = is_array($settings['styles'][$settings['theme']] ?? null)
                    ? $settings['styles'][$settings['theme']]
                    : [];
                foreach (\Serve\themeHandles($settings) as $handle) {
                    if (!array_key_exists($handle['var'], $handles)) {
                        continue; // not on the tray — the stored value stands
                    }
                    $value = $handles[$handle['var']];
                    if (!is_string($value)) {
                        continue;
                    }
                    $value = trim($value);
                    if ($value === '') {
                        unset($stored[$handle['var']]); // empty = back to the default
                        continue;
                    }
                    if (!\Serve\validHandleValue($handle['type'], $value)) {
                        Router\refuse(400, "The handle {$handle['var']} refuses that value.");
                    }
                    $stored[$handle['var']] = $value;
                }
                $settings['styles'] = array_merge(
                    is_array($settings['styles'] ?? null) ? $settings['styles'] : [],
                    [$settings['theme'] => $stored]
                );
            }
            Site\save($settings);
            echo json_encode(['ok' => true]);
            break;

        // ——— site assets: the logo's upload door (admin) + public serving ———
        case $method === 'POST' && $path === 'site-assets':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $upload = $_FILES['file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name']) || !is_string($upload['name'])) {
                Router\refuse(400, 'You promised pastry and brought an empty plate — no file in the upload.');
            }
            $stored = Site\assetStore($upload['name'], $upload['tmp_name']);
            http_response_code(201);
            echo json_encode($stored, JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'GET' && preg_match('#^site-assets/[A-Za-z0-9._-]+$#', $path) === 1:
            // Public: the logo is the public face.
            $assetName = substr($path, strlen('site-assets/'));
            $assetPath = Site\assetPath((string)$assetName);
            if ($assetPath === null) {
                Router\refuse(404, 'No such asset on the shelf.');
            }
            header('Content-Type: ' . \Store\mediaMime((string)$assetName));
            header('Content-Length: ' . (string)filesize($assetPath));
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            // Raw bytes served on the site origin: the sandbox CSP turns any
            // smuggled script (SVG is scriptable!) into dead weight.
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
            readfile($assetPath);
            exit;

        // ——— the Vitrine (public wares showcase): admin's fetch + install lid ———
        case $method === 'GET' && $path === 'wares':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            echo json_encode(Wares\status(), JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'POST' && $path === 'wares/install':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $name = is_string($body['name'] ?? null) ? $body['name'] : '';
            echo json_encode(Wares\install($name), JSON_UNESCAPED_SLASHES);
            break;

        // ——— plugins (p6): the admin's install lid ———
        case $method === 'GET' && $path === 'plugins':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            echo json_encode(Plugins\all(), JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'PUT' && $path === 'plugins':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $wanted = is_array($body['enabled'] ?? null) ? $body['enabled'] : null;
            if ($wanted === null) {
                Router\refuse(400, 'That plugin map is unbaked dough — send a proper enabled map.');
            }
            // Only INSTALLED plugins may be toggled — the lid holds.
            $installed = array_filter(Plugins\all(), fn($p) => !isset($p['error']));
            $installedBy = [];
            $clean = [];
            foreach ($installed as $p) {
                $installedBy[$p['name']] = $p;
                $clean[$p['name']] = $wanted[$p['name']] === true;
            }
            // The chain law at the switch (2026-11-07): the world AFTER this
            // write is DERIVED — intent ∧ deps effective — and a plugin may
            // only ride that world. A requested-on plugin whose chain never
            // completes is refused by name; rings never promote, they refuse
            // here, at the switch, before any state is written.
            $manifests = [];
            foreach ($installed as $p) {
                $manifests[$p['name']] = $p;
            }
            $effAfter = Plugins\deriveEffective($manifests, $clean);
            $versions = array_map(fn($p) => (string)$p['version'], $manifests);
            foreach ($clean as $name => $on) {
                if (!$on) {
                    continue;
                }
                $unmet = \Chain\unmet($manifests[$name]['requiresParsed'], \Chain\pool($versions, $effAfter));
                if ($unmet !== []) {
                    Router\refuse(409, "'" . ($installedBy[$name]['title'] ?? $name) . "' waits for "
                        . implode(', ', $unmet) . " to stand effective first — a plugin does not run on a sleeping dependency.");
                }
            }
            Plugins\setState($clean);
            echo json_encode(['ok' => true]);
            break;

        // ——— plugin deletion (2026-11-10, user order): the shelf loses it. ———
        // No plugin is undeletable — the preinstalled default (hello) goes
        // like any other; the house-default law is a TEMPLATE law.
        case $method === 'POST' && $path === 'plugin/delete':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $name = is_string($body['name'] ?? null) ? $body['name'] : '';
            echo json_encode(Wares\removePlugin((string)$name), JSON_UNESCAPED_SLASHES);
            break;

        // ——— plugin settings (2026-11-11): the manifest-declared fields, admin+CSRF ———
        case $method === 'POST' && $path === 'plugin/settings':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $name = is_string($body['name'] ?? null) ? $body['name'] : '';
            $values = is_array($body['settings'] ?? null) ? $body['settings'] : [];
            echo json_encode(Plugins\saveSettings((string)$name, $values), JSON_UNESCAPED_SLASHES);
            break;

        // ——— page types (p6-era UX): pre-made, fillable scaffolds from pagetypes/ ———
        case $method === 'GET' && $path === 'pagetypes':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            $types = [];
            foreach (scandir(dirname(__DIR__) . '/pagetypes') ?: [] as $file) {
                if (!str_ends_with($file, '.md') || str_starts_with($file, '.')) {
                    continue;
                }
                $name = substr($file, 0, -3);
                if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
                    continue;
                }
                $types[] = [
                    'name' => $name,
                    'title' => ucfirst(str_replace('-', ' ', $name)),
                    'body' => (string)file_get_contents(dirname(__DIR__) . '/pagetypes/' . $file),
                ];
            }
            usort($types, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
            echo json_encode($types, JSON_UNESCAPED_SLASHES);
            break;

        // ——— templates (theme-contract wares): activate the live theme, bury a dead one ———
        // Singular on purpose: /bridge/templates/<name>/… belongs to the theme's
        // static assets (the root front controller exempts that prefix), these
        // two JSON verbs must not be swallowed by it.
        case $method === 'POST' && $path === 'template/activate':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $name = is_string($body['name'] ?? null) ? $body['name'] : '';
            if (preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $name) !== 1
                || !is_file(__DIR__ . '/templates/' . $name . '/theme.json')) {
                Router\refuse(400, "Unknown template: {$name}");
            }
            // The chain law at the wardrobe: a template dresses the whole site,
            // so its manifest's requirements must stand EFFECTIVE first — a
            // theme whose dependencies sleep would serve a half-dressed site.
            $themeManifest = json_decode((string)@file_get_contents(__DIR__ . '/templates/' . $name . '/theme.json'), true);
            $requiresParsed = is_array($themeManifest) ? \Chain\parse($themeManifest['requires'] ?? []) : null;
            if ($requiresParsed === null) {
                Router\refuse(400, "'{$name}' declares requirements this core cannot read — entries are \"name\" or \"name >=1.2\".");
            }
            foreach ($requiresParsed as $req) {
                if ($req['name'] === $name) {
                    Router\refuse(400, "A template does not require itself.");
                }
            }
            $pool = [];
            foreach (Plugins\all() as $p) {
                if (isset($p['error'])) {
                    continue;
                }
                $pool[$p['name']] = ['version' => (string)$p['version'], 'effective' => ($p['effective'] ?? false) === true];
            }
            $unmet = \Chain\unmet($requiresParsed, $pool);
            if ($unmet !== []) {
                Router\refuse(409, "'{$name}' dresses a site that stands on " . implode(', ', $unmet)
                    . ' — enable ' . (count($unmet) === 1 ? 'it' : 'them') . ' first, then activate.');
            }
            $settings = Site\load();
            $settings['theme'] = $name;
            Site\save($settings); // the opcache drop lives in Site\save — the switch lands at once
            \Wares\seedBlanko($name); // an empty site wakes dressed in its new theme — never a 404
            echo json_encode(['ok' => true, 'theme' => $name], JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'POST' && $path === 'template/delete':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $name = is_string($body['name'] ?? null) ? $body['name'] : '';
            echo json_encode(Wares\removeTemplate((string)$name), JSON_UNESCAPED_SLASHES);
            break;

        // ——— the template preview (sidequest, 2026-11-07): the admin's page,
        // dressed in ANY installed theme — the shell door renders it client-side,
        // so the preview obeys all three doors' law by construction. ———
        case $method === 'GET' && preg_match('#^preview/[a-z0-9-]+/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — the fitting room is not for strangers.');
            }
            // The fitting room answers a PAGE, not the API's JSON (2026-11-10):
            // the theme preview is a dressed page, so the door says so.
            header('Content-Type: text/html; charset=utf-8');
            [, $themeName, $type, $slug] = explode('/', $path);
            if (!is_file(__DIR__ . '/templates/' . $themeName . '/theme.json')) {
                Router\refuse(404, "No such theme: {$themeName}");
            }
            $site = \Site\load();
            $site['theme'] = $themeName; // the override — the whole template chain reads it
            echo \Serve\shell($site, $type, $slug);
            break;

        // ——— the drawer's retail band (2026-11-07, user law): quality-of-life
        // edit handles from enabled plugins + the active theme, manifest-declared.
        // Any authed hand may read it — editors carry the drawer too. ———
        case $method === 'GET' && $path === 'drawer':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            echo json_encode([
                'handles' => array_merge(\Plugins\drawerHandles(), \Serve\themeDrawerHandles()),
            ], JSON_UNESCAPED_SLASHES);
            break;

        // ——— the core's self-update: GitHub versions or a parked local packet ———
        case $method === 'GET' && $path === 'core':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            $ann = \Core\announce();
            echo json_encode([
                'current' => \Core\version(),
                'latest' => $ann['latest'] !== null ? ltrim((string)$ann['latest'], 'v') : null,
                'dashboard' => \Core\dashboardVersion(), // the face's own version — null = unmarked (counts stale)
                'updatable' => \Core\updatable($ann), // the ONE verdict — the announce in hand, no second GitHub trip
                'error' => (string)$ann['error'],
                'localVersion' => \Core\peekPacket(\Core\LOCAL_PACKET),
                // the attribution card's words: the release's own name + day,
                // and GitHub's contributor list (the non-GitHub names ride
                // the dashboard — the core carries only what GitHub knows).
                'releaseName' => $ann['name'] ?? null,
                'published' => $ann['published'] ?? null,
                'contributors' => \Core\contributors(),
            ], JSON_UNESCAPED_SLASHES);
            break;

        // ——— the GitHub door: the account, and the repos it may share ———
        case $method === 'GET' && $path === 'github':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — an account is the admin\'s hand.');
            }
            echo json_encode([
                'connected' => \Github\token() !== null,
                'login' => \Github\account(), // null when there is none, or the token is dead
            ], JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'POST' && $path === 'github/connect':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — an account is the admin\'s hand.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $token = (string)($body['token'] ?? '');
            if (trim($token) === '') {
                Router\refuse(400, 'An empty token opens no door.');
            }
            \Github\remember(trim($token), ''); // the login is read back below
            $login = \Github\account();
            if ($login === null) {
                \Github\forget(); // the token is dead — it does not stay
                Router\refuse(400, 'GitHub refused that token — check its scopes and its life.');
            }
            \Github\remember(trim($token), $login);
            echo json_encode(['connected' => true, 'login' => $login], JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'POST' && $path === 'github/test':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — an account is the admin\'s hand.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            echo json_encode(\Github\test((string)($body['token'] ?? '')), JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'POST' && $path === 'github/disconnect':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — an account is the admin\'s hand.');
            }
            Auth\assertCsrf();
            \Github\forget();
            echo json_encode(['connected' => false, 'login' => null], JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'GET' && $path === 'github/inspect':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — an account is the admin\'s hand.');
            }
            $repo = (string)($_GET['repo'] ?? '');
            echo json_encode(\Github\inspect($repo), JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'POST' && $path === 'github/install':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — an account is the admin\'s hand.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            echo json_encode(\Github\install(
                (string)($body['repo'] ?? ''),
                (string)($body['kind'] ?? ''),
                (string)($body['path'] ?? '')
            ), JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'POST' && $path === 'core/packet':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            echo json_encode(\Core\parkPacket(), JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'POST' && $path === 'core/update':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $source = is_string($body['source'] ?? null) ? $body['source'] : 'hub';
            echo json_encode(\Core\update((string)$source), JSON_UNESCAPED_SLASHES);
            break;

        // Route: GET /bridge/templates — the theme contract's installed wares with
        // their manifest face plus the active flag. Admin-only: the ware inventory
        // (like the Plugins tab) is the operator's concern, not editors'.
        case $method === 'GET' && $path === 'templates':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            $activeTheme = \Site\load()['theme'] ?? '';
            $wares = [];
            foreach (glob(__DIR__ . '/templates/*', GLOB_ONLYDIR) ?: [] as $themeDir) {
                $name = basename((string)$themeDir);
                if (!is_file($themeDir . '/theme.json') || preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $name) !== 1) {
                    continue;
                }
                $manifest = json_decode((string)file_get_contents($themeDir . '/theme.json'), true);
                $wares[] = [
                    'name' => $name,
                    'title' => is_string($manifest['title'] ?? null) ? $manifest['title'] : $name,
                    'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0',
                    'description' => is_string($manifest['description'] ?? null) ? $manifest['description'] : '',
                    'styles' => (array)($manifest['styles'] ?? []),
                    'defaultStyle' => is_string($manifest['defaultStyle'] ?? null) ? $manifest['defaultStyle'] : '',
                    'default' => $name === \Site\DEFAULTS['theme'],
                    'active' => $activeTheme === $name,
                    'preview' => \Serve\themePreview($name), // the shelf image, or null
                ];
            }
            usort($wares, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
            echo json_encode($wares, JSON_UNESCAPED_SLASHES);
            break;

        // Plugin API routes: /bridge/plugin/<name>/<route> — auth/CSRF enforced by the contract.
        case preg_match('#^(GET|POST|PUT|DELETE)$#', $method) === 1
            && preg_match('#^plugin/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            [, $pluginName, $pluginRoute] = explode('/', $path);
            Plugins\dispatch((string)$pluginName, (string)$pluginRoute, $method);
            break;

        // ——— editor blocks (p6): the canvas insertables of every ENABLED plugin ———
        case $method === 'GET' && $path === 'editor-blocks':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            $defs = [];
            foreach (Plugins\all() as $plugin) {
                if (isset($plugin['error']) || $plugin['enabled'] !== true) {
                    continue;
                }
                foreach ($plugin['editorBlocks'] as $blockDef) {
                    $defs[] = is_array($blockDef)
                        ? $blockDef
                        : ['name' => $blockDef, 'label' => $plugin['title'], 'template' => ''];
                }
            }
            echo json_encode($defs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        // ——— account management: admin-only, CSRF-gated ———
        case $method === 'GET' && $path === 'accounts':
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
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
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $user = is_string($body['user'] ?? null) ? $body['user'] : '';
            $password = is_string($body['password'] ?? null) ? $body['password'] : '';
            $role = is_string($body['role'] ?? null) ? $body['role'] : 'editor';
            $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
            if (strlen($password) < 8) {
                Router\refuse(400, 'That password is too thin to fold — give it 8+ characters.');
            }
            Accounts\add($user, password_hash($password, PASSWORD_ARGON2ID), $role, $email);
            http_response_code(201);
            echo json_encode(['ok' => true]);
            break;

        case $method === 'PUT' && preg_match('#^account/[a-z0-9][a-z0-9._-]{0,63}$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
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
                Router\refuse(409, 'Someone has to run the oven — the last admin stays at the pass.');
            }
            if ($roles !== null) {
                Accounts\setRole($target, $roles);
            }
            if (is_string($body['email'] ?? null)) {
                Accounts\setEmail($target, trim($body['email']));
            }
            if (is_string($body['password'] ?? null)) {
                if (strlen($body['password']) < 8) {
                    Router\refuse(400, 'That password is too thin to fold — give it 8+ characters.');
                }
                Accounts\setHash($target, password_hash($body['password'], PASSWORD_ARGON2ID));
            }
            echo json_encode(['ok' => true]);
            break;

        case $method === 'DELETE' && preg_match('#^account/[a-z0-9][a-z0-9._-]{0,63}$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            if (!Auth\isAdmin()) {
                Router\refuse(403, 'Admins only — editors do not touch the sugar safe.');
            }
            Auth\assertCsrf();
            $target = substr($path, strlen('account/'));
            $accounts = Accounts\all();
            if ($target === Auth\user()) {
                Router\refuse(409, 'You cannot eat your own chair — that account sits behind this session.');
            }
            if (!isset($accounts[$target])) {
                Router\refuse(404, "No account: {$target}");
            }
            if ($accounts[$target]['role'] === 'admin'
                && count(array_filter($accounts, fn($r) => $r['role'] === 'admin')) === 1) {
                Router\refuse(409, 'The last admin keeps the lights on — no eating the baker.');
            }
            Accounts\remove($target);
            echo json_encode(['ok' => true]);
            break;


        case $method === 'GET' && $path === 'pages':
            // Public listing for strangers (drafts filtered inside), full tree for editors.
            // The home page wears its crown (2026-11-07): the desk refuses to delete it.
            // Titles ride with the index (2026-11-10, closes the BACKLOG item): titleOf
            // is a memo hit — listAll primed it with the bytes already in hand.
            $tree = Store\listAll();
            foreach ($tree as $type => $pages) {
                foreach ($pages as $i => $p) {
                    $tree[$type][$i]['title'] = Serve\titleOf((string)$type, (string)$p['slug'], (string)$p['slug']);
                }
            }
            $home = \Serve\homeSlug(\Site\load());
            if ($home !== '' && isset($tree['pages'])) {
                foreach ($tree['pages'] as $i => $p) {
                    if (($p['slug'] ?? null) === $home) {
                        $tree['pages'][$i]['home'] = true;
                    }
                }
            }
            echo json_encode($tree, JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'GET' && $path === 'search':
            // The Contents desk's needle: authed only — it reads drafts. A byte
            // search over the editorial copies, the listAll shape back.
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            $q = is_string($_GET['q'] ?? null) ? trim((string)$_GET['q']) : '';
            if ($q === '') {
                Router\refuse(400, 'The search needs a needle — q cannot be empty.');
            }
            echo json_encode(Store\search($q), JSON_UNESCAPED_SLASHES);
            break;

        // ——— edit locks: heartbeat in warm, silence and polite exit free ———
        // ——— page lifecycle: delete + rename (lock-gated, CSRF-gated) ———
        case $method === 'DELETE' && preg_match('#^page/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            // The home page stands (2026-11-07, user law): the index page cannot
            // be deleted — the lid lives HERE, server-side, not in a hidden button.
            if ($type === 'pages' && $slug === \Serve\homeSlug(\Site\load())) {
                Router\refuse(409, 'The home page stands — the index page cannot be deleted.');
            }
            Lock\assertFreeOrMine($type, $slug, Auth\user());
            Store\remove($type, $slug);
            Cache\drop($type, $slug);
            echo json_encode(['ok' => true]);
            break;
        case $method === 'POST' && preg_match('#^page/[a-z0-9-]+/[a-z0-9-]+/rename$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            Lock\assertFreeOrMine($type, $slug, Auth\user());
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $to = is_string($body['slug'] ?? null) ? $body['slug'] : '';
            Store\move($type, $slug, $to);
            Cache\drop($type, $slug);
            Cache\drop($type, $to);
            echo json_encode(['ok' => true, 'slug' => $to]);
            break;

        // ——— page-local media: upload via modal, serve raw, immutable by law ———
        case $method === 'GET' && $path === 'media':
            // The overlay's media tab: every page's files in one lazy sweep.
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            $allMedia = [];
            $folders = ['', 'stop-motion']; // the page tray + the dedicated stop-motion folder
            foreach (Store\listAll() as $type => $pages) {
                foreach ($pages as $page) {
                    $slug = (string)$page['slug'];
                    foreach ($folders as $folder) {
                        try {
                            foreach (Store\mediaList($type, $slug, $folder) as $file) {
                                $allMedia[] = [
                                    'type' => $type,
                                    'slug' => $slug,
                                    'folder' => $folder,
                                    'name' => $file['name'],
                                    'size' => $file['size'],
                                    'url' => "/bridge/media/{$type}/{$slug}"
                                        . ($folder !== '' ? '/' . $folder : '') . '/' . rawurlencode((string)$file['name']),
                                ];
                            }
                        } catch (\Throwable) {
                            continue; // a vanished page never breaks the sweep
                        }
                    }
                }
            }
            echo json_encode($allMedia, JSON_UNESCAPED_SLASHES);
            break;

        case $method === 'GET' && preg_match('#^media/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            [, $type, $slug] = explode('/', $path);
            echo json_encode(Store\mediaList($type, $slug, mediaFolderParam()), JSON_UNESCAPED_SLASHES);
            break;
        case $method === 'POST' && preg_match('#^media/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            $upload = $_FILES['file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name']) || !is_string($upload['name'])) {
                Router\refuse(400, 'You promised pastry and brought an empty plate — no file in the upload.');
            }
            $stored = Store\mediaStore($type, $slug, $upload['name'], $upload['tmp_name'], mediaFolderParam());
            http_response_code(201);
            echo json_encode($stored, JSON_UNESCAPED_SLASHES);
            break;
        // DELETE — a named media file leaves its tin (page dir or sub-folder).
        // Immutability guards overwrites, not existence; the hand is CSRF-gated.
        case $method === 'DELETE' && preg_match('#^media/[a-z0-9-]+/[a-z0-9-]+/[A-Za-z0-9._-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            Auth\assertCsrf();
            [, $type, $slug, $name] = explode('/', $path);
            Store\mediaDelete($type, $slug, (string)$name, mediaFolderParam());
            echo json_encode(['ok' => true]);
            break;
        case $method === 'GET' && preg_match('#^media/[a-z0-9-]+/[a-z0-9-]+(?:/[a-z0-9-]+)?/[A-Za-z0-9._-]+$#', $path) === 1:
            $parts = explode('/', $path);
            if (count($parts) === 4) {
                [, $type, $slug, $name] = $parts;
                $mediaFolder = '';
            } else {
                [, $type, $slug, $mediaFolder, $name] = $parts;
            }
            $path = Store\mediaPath($type, $slug, (string)$name, $mediaFolder);
            if ($path === null) {
                Router\refuse(404, 'That media crumb is not in the tin.');
            }
            header('Content-Type: ' . Store\mediaMime((string)$name));
            header('Content-Length: ' . (string)filesize($path));
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            // Raw bytes served on the site origin: the sandbox CSP turns any
            // smuggled script (SVG is scriptable!) into dead weight.
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
            readfile($path);
            exit;

        // ——— editorial drafts + publishing: the editor's dirty little secret ———

        // GET — editorial copy (sidecar or published file, whichever freshest in life).
        case $method === 'GET' && preg_match('#^draft/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            [, $type, $slug] = explode('/', $path);
            $raw = Store\readDraft($type, $slug);
            if ($raw === null) {
                Router\refuse(404, "Page not found: {$type}/{$slug}");
            }
            header('Content-Type: text/markdown; charset=utf-8');
            header('X-Has-Draft: ' . (Store\hasDraft($type, $slug) ? '1' : '0'));
            header('X-Content-Hash: ' . Store\draftHash($type, $slug));
            echo $raw;
            break;

        // PUT — autosave-to-draft lives here. Lock-gated like page writes.
        case $method === 'PUT' && preg_match('#^draft/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            Auth\assertCsrf();
            [, $type, $slug] = explode('/', $path);
            Lock\assertFreeOrMine($type, $slug, Auth\user());
            // X-Base-Hash: the fingerprint the client based its edit on. Mismatch → 409,
            // never a silent clobber (flaky-internet guardrail).
            $baseHash = $_SERVER['HTTP_X_BASE_HASH'] ?? null;
            $hash = Store\writeDraft(
                $type,
                $slug,
                file_get_contents('php://input') ?: '',
                is_string($baseHash) && $baseHash !== '' ? $baseHash : null
            );
            Lock\acquire($type, $slug, Auth\user());
            echo json_encode(['ok' => true, 'hash' => $hash]);
            break;

        // POST — `draft/<t>/<s>/publish` and `/unpublish`. The button behind the glass.
        case $method === 'POST' && preg_match('#^draft/[a-z0-9-]+/[a-z0-9-]+/(publish|unpublish)$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
            }
            Auth\assertCsrf();
            [, $type, $slug, $verb] = explode('/', $path);
            Lock\assertFreeOrMine($type, $slug, Auth\user());
            if (is_string($verb) && $verb === 'publish') {
                // The browser renders (GOSPEL: no Markdown parsing in PHP) — the
                // rendered body rides along in the JSON body and goes straight
                // into the HTML cache. No HTML? The fallback shell covers it.
                $body = json_decode(file_get_contents('php://input') ?: '{}', true);
                $html = is_string($body['html'] ?? null) ? $body['html'] : '';
                Store\publish($type, $slug);
                Cache\drop($type, $slug); // raw + HTML caches (drop() sweeps both)
                // The leash: editors' publishes get executable HTML stripped
                // server-side — the client's render is trusted only as far as
                // a legit render could have produced it. Admins keep raw HTML.
                if ($html !== '') {
                    Cache\storeHtml($type, $slug, Auth\isAdmin() ? $html : stripExecutableHtml($html));
                }
                Lock\acquire($type, $slug, Auth\user());
                echo json_encode(['ok' => true, 'published' => true]);
            } else {
                Store\unpublish($type, $slug);
                Cache\drop($type, $slug);
                echo json_encode(['ok' => true, 'published' => false]);
            }
            break;

        // Route: GET /bridge/page/{type}/{slug} — raw markdown, body passthrough (not JSON).
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

        // ——— public JSON API: read-only, per page — Schmarrn doubles as a
        // ——— quick-deploy backend. Anonymous by design; CORS opens wide (GET only).
        // Route: GET /bridge/public/{type}/{slug} — title, rendered body HTML (or null
        // when the cache is stale/missing), sibling pages for menus, page-local files.
        case $method === 'GET' && preg_match('#^public/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            [, $type, $slug] = explode('/', $path);
            if (Store\read($type, $slug) === null || Store\isDraft($type, $slug)) {
                Router\refuse(404, "Page not found: {$type}/{$slug}");
            }
            // Staleness first, body second (the optimization pass): a stale
            // render costs the mtime peek only, never a wasted body read.
            $bodyHtml = !Cache\htmlStale($type, $slug) ? Cache\html($type, $slug) : null;
            $children = [];
            foreach (Store\listAll()[$type] ?? [] as $sibling) {
                if (($sibling['slug'] ?? '') !== $slug && !($sibling['draft'] ?? false)) {
                    $childSlug = (string)$sibling['slug'];
                    $children[] = ['slug' => $childSlug, 'title' => Serve\titleOf($type, $childSlug, $childSlug)];
                }
            }
            $files = [];
            foreach (Store\mediaList($type, $slug) as $file) {
                $files[] = [
                    'name' => $file['name'],
                    'size' => $file['size'],
                    'url' => "/bridge/media/{$type}/{$slug}/" . rawurlencode((string)$file['name']),
                ];
            }
            echo json_encode([
                'type' => $type,
                'slug' => $slug,
                'title' => Serve\titleOf($type, $slug, $slug),
                'bodyHtml' => $bodyHtml,
                'children' => $children,
                'files' => $files,
            ], JSON_UNESCAPED_SLASHES);
            break;

        // Route: POST /bridge/page/{type}/{slug} — create (unfold) a fresh page; 409 if it exists.
        case $method === 'POST' && preg_match('#^page/[a-z0-9-]+/[a-z0-9-]+$#', $path) === 1:
            if (!Auth\check()) {
                Router\refuse(401, 'The kitchen door is locked — log in first.');
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
                Router\refuse(401, 'The kitchen door is locked — log in first.');
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
            Router\refuse(404, 'No recipe for that route.');
    }
} catch (Throwable $e) {
    @error_log('[schmarrn-500] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Router\refuse(500, 'Something curdled in the kitchen. The log knows more.');
}
