<?php
/**
 * The GitHub door (2026-11-12, user order: the Integrations card).
 *
 * Schmarrn can be given a GitHub account — a Personal Access Token the admin
 * pastes in the dashboard — so private repos of that account can be read as
 * plugin or template sources. Without a token, PUBLIC repos still work: the
 * door is the same, the reach is shorter.
 *
 * THE SAFETY GATE (user law): an arriving repo's CODE NEVER RUNS HERE. The
 * install copies files onto the shelf, reads the manifest, lides the code
 * (the same lid the Vitrine install uses — \Plugins\codeCheck) and STAGES the
 * ware DISABLED. The admin's Enable click is the approval that runs it.
 * Nothing in this file ever includes, requires or executes a ware's code.
 *
 * SPDX-License-Identifier: MPL-2.0
 * Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Github;

const API = 'https://api.github.com';
const BYTE_CAP = 20 * 1024 * 1024; // a repo zip under the roof — 20 MB

/** The account store — git-ignored, writable, one file. */
function configFile(): string
{
    return __DIR__ . '/config/github.local.php';
}

/** The provenance ledger — what came from where, and when. */
function lockFile(): string
{
    return __DIR__ . '/config/github.lock.php';
}

/** The stored account, or an empty array. */
function state(): array
{
    $file = configFile();
    if (!is_file($file)) {
        return [];
    }
    $data = require $file;
    return is_array($data) ? $data : [];
}

/** The token, or null when no account is connected. */
function token(): ?string
{
    $t = state()['token'] ?? null;
    return is_string($t) && $t !== '' ? $t : null;
}

/** The connected account's login, or null. */
function login(): ?string
{
    $l = state()['login'] ?? null;
    return is_string($l) && $l !== '' ? $l : null;
}

/** Remember the account — the token and the login it belongs to. */
function remember(string $token, string $login): void
{
    $dir = __DIR__ . '/config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = configFile();
    @file_put_contents($file, "<?php\n// GitHub account — written by the dashboard's Integrations card.\nreturn " . var_export([
        'token' => $token,
        'login' => $login,
        'at' => time(),
    ], true) . ";\n");
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
}

/** Let the account go — the token dies with the file. */
function forget(): void
{
    $file = configFile();
    if (is_file($file)) {
        @unlink($file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}

/**
 * One GitHub call. Returns ['status' => int, 'data' => array|null].
 * The token rides when there is one; without it GitHub answers for public
 * repos only (a private repo answers 404 — GitHub's own courtesy).
 */
function api(string $path, string $method = 'GET', ?array $body = null, ?string $asToken = null): array
{
    $headers = [
        'User-Agent: schmarrn-core/1.0',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $t = $asToken ?? token();
    if ($t !== null) {
        $headers[] = 'Authorization: Bearer ' . $t;
    }
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 20,
            'ignore_errors' => true, // a 404/401 body is news, not a dead end
        ],
    ];
    if ($body !== null) {
        $opts['http']['header'] .= "\r\nContent-Type: application/json";
        $opts['http']['content'] = (string)json_encode($body);
    }
    $raw = @file_get_contents(API . $path, false, stream_context_create($opts));
    if ($raw === false) {
        return ['status' => 0, 'data' => null];
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int)$m[1];
        }
    }
    $data = json_decode((string)$raw, true);
    return ['status' => $status, 'data' => is_array($data) ? $data : null];
}

/** Who am I? The token's account — null when there is none or it is dead. */
function account(): ?string
{
    if (token() === null) {
        return null;
    }
    return identify(token());
}

/** The account behind a token — GitHub's answer, or null. */
function identify(string $candidate): ?string
{
    $res = api('/user', 'GET', null, $candidate);
    if ($res['status'] !== 200 || !is_array($res['data'])) {
        return null;
    }
    $login = $res['data']['login'] ?? null;
    return is_string($login) && $login !== '' ? $login : null;
}

/**
 * TEST a token without keeping it (2026-11-12, user order: the modal's
 * "Test connection" button). The token lives for one call and dies — nothing
 * is written to disk unless the admin says "connect".
 */
function test(string $candidate): array
{
    if (trim($candidate) === '') {
        return ['ok' => false, 'login' => null, 'error' => 'An empty token opens no door.'];
    }
    $login = identify(trim($candidate));
    if ($login === null) {
        return ['ok' => false, 'login' => null, 'error' => 'GitHub refused that token — check its scopes and its life.'];
    }
    return ['ok' => true, 'login' => $login, 'error' => ''];
}

/**
 * Read a repo reference — "owner/repo", a full GitHub URL, or a .git suffix.
 * Returns [owner, repo] or null when the input is not a repo at all.
 */
function parseRepo(string $input): ?array
{
    $s = trim($input);
    if ($s === '') {
        return null;
    }
    if (preg_match('~github\.com[/:]([^/\s]+)/([^/\s\#?]+)~i', $s, $m) === 1) {
        $owner = $m[1];
        $repo = $m[2];
    } elseif (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $s, $m) === 1) {
        $owner = $m[1];
        $repo = $m[2];
    } else {
        return null;
    }
    $repo = (string)preg_replace('/\.git$/', '', $repo);
    if (preg_match('/^[A-Za-z0-9_.-]+$/', $owner) !== 1 || preg_match('/^[A-Za-z0-9_.-]+$/', $repo) !== 1) {
        return null;
    }
    return [$owner, $repo];
}

/** The manifest behind a path in the repo (base64 in, array out). */
function fileAt(string $owner, string $repo, string $path, string $ref): array
{
    $res = api('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/' . $path . '?ref=' . rawurlencode($ref));
    if ($res['status'] === 403) {
        return ['status' => 403, 'data' => null];
    }
    if ($res['status'] !== 200 || !is_array($res['data'])) {
        return ['status' => $res['status'], 'data' => null];
    }
    $raw = $res['data']['content'] ?? null;
    if (!is_string($raw)) {
        return ['status' => $res['status'], 'data' => null];
    }
    $decoded = base64_decode(str_replace("\n", '', $raw), true);
    if ($decoded === false) {
        return ['status' => $res['status'], 'data' => null];
    }
    $data = json_decode($decoded, true);
    return ['status' => $res['status'], 'data' => is_array($data) ? $data : null];
}

/**
 * LOOK at a repo — does it hold a Schmarrn plugin or template, and does its
 * manifest answer for itself? Nothing is installed, nothing is run.
 *
 * ONE GitHub call does the looking: the git TREE lists every path in the
 * repo, so a monorepo (wares in plugins/<name>/plugin.json) and a
 * single-ware repo (plugin.json at the root) are both found without
 * walking folders — and the 60/h budget is spared.
 *
 * THE BUDGET LAW (2026-11-12, learned live): GitHub's unauthenticated calls
 * are 60 per hour PER IP — a shared host's pool is spent by strangers. The
 * look is cached ten minutes per repo (the token counts: a private repo
 * looks different with and without an account), and a spent budget says so
 * out loud instead of pretending the manifest is unreadable.
 */
function inspect(string $input): array
{
    $parsed = parseRepo($input);
    if ($parsed === null) {
        return ['error' => 'That is not a repo reference — give owner/repo or a GitHub URL.'];
    }
    [$owner, $repo] = $parsed;
    $cacheFile = __DIR__ . '/cache/github-look-' . sha1(strtolower($owner . '/' . $repo) . '|' . (token() !== null ? 'auth' : 'anon')) . '.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['at']) && (time() - (int)$cached['at']) < 600 && is_array($cached['look'] ?? null)) {
            return $cached['look'];
        }
    }
    $look = lookNow($owner, $repo);
    if (($look['error'] ?? '') === '') {
        @file_put_contents($cacheFile, json_encode(['at' => time(), 'look' => $look], JSON_UNESCAPED_SLASHES));
    }
    return $look;
}

/** The look itself — the calls that spend the budget. */
function lookNow(string $owner, string $repo): array
{
    $meta = api('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo));
    if ($meta['status'] === 404) {
        return ['error' => token() !== null
            ? "'{$owner}/{$repo}' is not visible to this account — check the token's repo scope, or the name."
            : "'{$owner}/{$repo}' does not exist, or it is private — connect a GitHub account to reach it."];
    }
    if ($meta['status'] === 403) {
        return ['error' => 'GitHub\'s hourly budget for this host is spent (unauthenticated calls are 60/h) — connect a GitHub account for 5000/h, or wait for the clock to turn.'];
    }
    if ($meta['status'] === 0) {
        return ['error' => 'GitHub does not answer right now.'];
    }
    if ($meta['status'] !== 200 || !is_array($meta['data'])) {
        return ['error' => "GitHub refused the look (HTTP {$meta['status']})."];
    }
    $data = $meta['data'];
    $branch = is_string($data['default_branch'] ?? null) ? $data['default_branch'] : 'main';
    $out = [
        'repo' => is_string($data['full_name'] ?? null) ? (string)$data['full_name'] : "{$owner}/{$repo}",
        'private' => ($data['private'] ?? false) === true,
        'branch' => $branch,
        'description' => is_string($data['description'] ?? null) ? (string)$data['description'] : '',
        'wares' => [],
        'error' => '',
    ];

    $tree = api('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/trees/' . rawurlencode($branch) . '?recursive=1');
    if ($tree['status'] === 403) {
        $out['error'] = 'GitHub\'s hourly budget for this host is spent — connect a GitHub account for 5000/h, or wait for the clock to turn.';
        return $out;
    }
    if ($tree['status'] !== 200 || !is_array($tree['data']) || !is_array($tree['data']['tree'] ?? null)) {
        $out['error'] = 'The repo\'s file tree did not come through — try again in a moment.';
        return $out;
    }
    $candidates = [];
    foreach ($tree['data']['tree'] as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
            continue;
        }
        $path = (string)$entry['path'];
        if (str_contains($path, 'node_modules/') || str_contains($path, 'vendor/')) {
            continue;
        }
        if (preg_match('#(^|/)(plugin|theme)\.json$#', $path, $m) !== 1) {
            continue;
        }
        $candidates[] = ['kind' => $m[2] === 'plugin' ? 'plugin' : 'template', 'path' => $path, 'dir' => dirname($path)];
    }
    if ($candidates === []) {
        $out['error'] = "'{$owner}/{$repo}' holds no plugin.json and no theme.json — a Schmarrn ware needs its manifest in the repo.";
        return $out;
    }
    foreach ($candidates as $c) {
        $read = fileAt($owner, $repo, $c['path'], $branch);
        $manifest = $read['data'];
        $dir = $c['dir'] === '.' ? '' : $c['dir'];
        $name = is_array($manifest) && is_string($manifest['name'] ?? null) ? (string)$manifest['name'] : null;
        // the folder name is the shelf name — the manifest must answer to it
        $expected = $dir === '' ? $name : basename($dir);
        $ware = [
            'kind' => $c['kind'],
            'path' => $dir,
            'name' => $name ?? $expected,
            'title' => is_array($manifest) && is_string($manifest['title'] ?? null) ? (string)$manifest['title'] : ($name ?? ''),
            'version' => is_array($manifest) && is_string($manifest['version'] ?? null) ? (string)$manifest['version'] : null,
            'description' => is_array($manifest) && is_string($manifest['description'] ?? null) ? (string)$manifest['description'] : '',
            'error' => '',
        ];
        if ($read['status'] === 403) {
            $ware['error'] = 'GitHub\'s hourly budget is spent — the manifest could not be read.';
        } elseif (!is_array($manifest)) {
            $ware['error'] = 'The manifest could not be read.';
        } elseif ($name === null) {
            $ware['error'] = 'The manifest carries no name.';
        } elseif ($expected !== null && $name !== $expected) {
            $ware['error'] = "The manifest says '{$name}' but its folder says '{$expected}' — the name must match its folder.";
        } elseif (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string)$name) !== 1) {
            $ware['error'] = "The name '{$name}' is not a slug — this core's shelf names are lowercase slugs.";
        } elseif ($c['kind'] === 'plugin') {
            $verdict = \Plugins\validate((string)$name, $manifest);
            if (is_string($verdict)) {
                $ware['error'] = "The manifest does not pass this core: {$verdict}";
            }
        }
        $out['wares'][] = $ware;
    }
    return $out;
}

/** Stream a repo zipball to disk under the roof. */
function download(string $url, string $dest): bool
{
    $headers = ['User-Agent: schmarrn-core/1.0', 'Accept: application/vnd.github+json'];
    $t = token();
    if ($t !== null) {
        $headers[] = 'Authorization: Bearer ' . $t;
    }
    $src = @fopen($url, 'rb', false, stream_context_create(['http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'timeout' => 60,
        'ignore_errors' => true,
    ]]));
    if ($src === false) {
        return false;
    }
    $dst = @fopen($dest, 'wb');
    if ($dst === false) {
        @fclose($src);
        return false;
    }
    $total = 0;
    while (!feof($src)) {
        $chunk = @fread($src, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $total += strlen($chunk);
        if ($total > BYTE_CAP) {
            @fclose($src);
            @fclose($dst);
            @unlink($dest);
            return false;
        }
        @fwrite($dst, $chunk);
    }
    @fclose($src);
    @fclose($dst);
    return $total > 0;
}

/** Unpack a repo zip into a scratch dir; returns the repo's top dir or null.
 *  ZipArchive when the host ships it, PharData fallback (phar is core
 *  everywhere — the house pattern, same as the Vitrine's own extractor). */
function unpack(string $zip, string $scratch): ?string
{
    if (class_exists('\ZipArchive')) {
        $zipObj = new \ZipArchive();
        if ($zipObj->open($zip) !== true) {
            return null;
        }
        $zipObj->extractTo($scratch);
        $zipObj->close();
    } else {
        try {
            $phar = new \PharData($zip);
            $base = 'phar://' . str_replace('\\', '/', realpath($zip) ?: $zip) . '/';
            foreach (new \RecursiveIteratorIterator($phar) as $key => $file) {
                if ($file->isDir()) {
                    continue;
                }
                $entry = str_replace('\\', '/', (string)$key);
                if (str_starts_with($entry, $base)) {
                    $entry = substr($entry, strlen($base));
                }
                if ($entry === '' || str_contains($entry, '..')) {
                    continue;
                }
                $dest = $scratch . '/' . $entry;
                @mkdir(dirname($dest), 0775, true);
                @copy((string)$key, $dest);
            }
            unset($phar);
        } catch (\Throwable) {
            return null;
        }
    }
    $tops = glob($scratch . '/*', GLOB_ONLYDIR);
    if (!is_array($tops) || $tops === []) {
        return null;
    }
    // the zipball's own top dir (owner-repo-sha) holds the repo root
    return (string)$tops[0];
}

/** Copy a staged dir into the shelf — files only, never executed. */
function place(string $dir, string $target): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
        return false;
    }
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $path = $item->getPathname();
        $rel = substr($path, strlen($dir) + 1);
        if ($rel === '' || str_contains($rel, '..')) {
            continue;
        }
        if ($item->isDir()) {
            @mkdir($target . '/' . $rel, 0775, true);
        } else {
            @copy($path, $target . '/' . $rel);
        }
    }
    return true;
}

/**
 * STAGE a repo as a ware. The code is lided and copied — NEVER run. The ware
 * lands DISABLED: the admin's Enable click is the approval.
 *
 * $path is the ware's folder inside the repo ('' = the repo root itself).
 */
function install(string $input, string $kind, string $path = ''): array
{
    $parsed = parseRepo($input);
    if ($parsed === null) {
        \Router\refuse(400, 'That is not a repo reference — give owner/repo or a GitHub URL.');
    }
    [$owner, $repo] = $parsed;
    if (!in_array($kind, ['plugin', 'template'], true)) {
        \Router\refuse(400, 'A ware is either a plugin or a template — the kind must say which.');
    }
    $look = inspect($input);
    if (($look['error'] ?? '') !== '') {
        \Router\refuse(400, (string)$look['error']);
    }
    $ware = null;
    foreach ((array)($look['wares'] ?? []) as $candidate) {
        if (is_array($candidate)
            && ($candidate['kind'] ?? null) === $kind
            && (string)($candidate['path'] ?? '') === $path) {
            $ware = $candidate;
            break;
        }
    }
    if ($ware === null) {
        \Router\refuse(404, "That repo holds no such {$kind} — the look and the stage must agree.");
    }
    if (($ware['error'] ?? '') !== '') {
        \Router\refuse(400, (string)$ware['error']);
    }
    $name = (string)$ware['name'];

    $scratch = __DIR__ . '/cache/github-' . bin2hex(random_bytes(4));
    @mkdir($scratch, 0775, true);
    $zip = $scratch . '/repo.zip';
    $url = API . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zipball/' . rawurlencode((string)$look['branch']);
    if (!download($url, $zip)) {
        @unlink($zip);
        @rmdir($scratch);
        \Router\refuse(502, "The repo would not come down — GitHub refused, or the packet is over the roof.");
    }
    $top = unpack($zip, $scratch);
    @unlink($zip);
    if ($top === null) {
        @rmdir($scratch);
        \Router\refuse(400, "The repo would not open — the pack job failed.");
    }
    $wareDir = $top;
    if ($path !== '') {
        $candidate = $top . '/' . $path;
        if (!is_dir($candidate)) {
            @rmdir($scratch);
            \Router\refuse(400, "The repo's folder '{$path}' did not come down with it.");
        }
        $wareDir = $candidate;
    }

    $res = stageWare($wareDir, $kind, $name, $ware, (string)$look['repo'], $path);
    @rmdir($scratch);
    return $res;
}

/**
 * The staging half on its own: validate the manifest, LIDE the code, copy it
 * onto the shelf, write the provenance. The code is copied, never run — the
 * ware lands DISABLED and the admin's Enable click is the approval.
 */
function stageWare(string $wareDir, string $kind, string $name, array $ware, string $repo, string $path): array
{
    $home = $kind === 'plugin' ? __DIR__ . '/plugins' : __DIR__ . '/templates';
    $target = $home . '/' . $name;
    if (is_dir($target)) {
        \Router\refuse(409, "'{$name}' already sits on the shelf — delete it first if you want this one.");
    }
    if (!is_dir($home)) {
        @mkdir($home, 0775, true);
    }

    if ($kind === 'plugin') {
        $manifest = json_decode((string)@file_get_contents($wareDir . '/plugin.json'), true);
        $verdict = is_array($manifest) ? \Plugins\validate($name, $manifest) : 'manifest unreadable';
        if (!is_array($manifest) || ($manifest['name'] ?? null) !== $name || is_string($verdict)) {
            \Router\refuse(400, "'{$name}' arrived with a plugin.json this core refuses"
                . (is_string($verdict) ? ": {$verdict} — nothing staged." : ' — nothing staged.'));
        }
        // THE LID: the code must resolve — and it stays UNRUN. The enable
        // switch is the only door to running it, and the admin's hand.
        $fault = \Plugins\codeCheck($wareDir);
        if ($fault !== null) {
            \Router\refuse(400, "'{$name}' arrived with code this core refuses: {$fault} Nothing staged.");
        }
    } else {
        $manifest = json_decode((string)@file_get_contents($wareDir . '/theme.json'), true);
        if (!is_array($manifest)) {
            \Router\refuse(400, "'{$name}' arrived without an intact theme.json — nothing staged.");
        }
    }

    if (!place($wareDir, $target)) {
        \Router\refuse(500, "The ware would not land on the shelf — the folder refused.");
    }

    // provenance: the ledger remembers where this came from
    $ledger = [];
    if (is_file(lockFile())) {
        $read = require lockFile();
        $ledger = is_array($read) ? $read : [];
    }
    $ledger[$name] = [
        'kind' => $kind,
        'repo' => $repo,
        'path' => $path,
        'version' => (string)($ware['version'] ?? '0.0.0'),
        'installedAt' => time(),
    ];
    @file_put_contents(lockFile(), "<?php\n// GitHub provenance — what came from where.\nreturn " . var_export($ledger, true) . ";\n");
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(lockFile(), true);
    }
    \Plugins\forget(); // the shelf changed — the scan memo must go
    return [
        'name' => $name,
        'kind' => $kind,
        'version' => (string)($ware['version'] ?? '0.0.0'),
        'repo' => $repo,
        'enabled' => false, // staged DISABLED — the code does not run until the admin says so
    ];
}
