<?php

/**
 * 1. Relative path: bridge/wares.php
 * 2. Description: The Vitrine fetch + install engine (p6's wares slice). The
 *                 public Vitrine repo (swisd-project/vitrine) lists its wares in
 *                 one JSON (wares.json at the repo root — one GET, no API, no
 *                 token); the install packet is ONE zip of the whole branch
 *                 (codeload snapshot), from which ONLY <path>/ of the requested
 *                 ware is extracted. Discernment before enabling: dir name
 *                 matches [a-z0-9-]+, registry name matches the manifest name,
 *                 requiresSchmarrn satisfied by the host; upgrade = overwrite
 *                 only when the incoming version is GREATER (disable-first,
 *                 rollback on the swap), equal = "already installed". Broken
 *                 manifests having no version count as replaceable. Extraction:
 *                 ZipArchive when the host ships it, PharData fallback (phar
 *                 is core everywhere; read-only zip access survives
 *                 phar.readonly). A broken fetch or install degrades to an
 *                 answer — never a dead site. Endpoints live in index.php:
 *                 GET /bridge/wares (admin view), POST /bridge/wares/install
 *                 (admin + CSRF). URLs are overridable in
 *                 config/wares.local.php (git-ignored) for local tests.
 * 3. Expects: bridge/plugins/ + bridge/templates/ homes; ZipArchive or phar;
 *             allow_url_fopen for the two breaches out (registry + packet zip).
 * 4. Provides: Wares\config/registry/status/install (+ httpGet, scratch helpers).
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Wares;

// Library files stand alone: each requires its dependencies explicitly.
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/plugins.php';

const HOST_VERSION = '1.0.0'; // the repo's tagged floor (v1.0.0) — bump at ship
const REGISTRY_URL = 'https://raw.githubusercontent.com/swisd-project/vitrine/main/wares.json';
const PACKET_URL = 'https://codeload.github.com/swisd-project/vitrine/zip/heads/main';
const REGISTRY_TTL = 300; // s — the window stays honest, the fetch stays boring

/** Defaults, overridable in config/wares.local.php (git-ignored; local tests). */
function config(): array
{
    $urls = ['registryUrl' => REGISTRY_URL, 'packetUrl' => PACKET_URL, 'ttl' => REGISTRY_TTL];
    $file = __DIR__ . '/config/wares.local.php';
    if (is_file($file)) {
        $local = (array)(require $file);
        foreach (['registryUrl', 'packetUrl', 'ttl'] as $key) {
            if (isset($local[$key]) && (is_string($local[$key]) || is_int($local[$key]))) {
                $urls[$key] = $local[$key];
            }
        }
    }
    return $urls;
}

/** One breach out: GET with a timeout, no reuse, off-host money never at stake. */
function httpGet(string $url): ?string
{
    $context = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'schmarrn-wares/1.0'],
        'https' => ['timeout' => 20, 'user_agent' => 'schmarrn-wares/1.0']]);
    $bytes = @file_get_contents($url, false, $context);
    return $bytes === false ? null : $bytes;
}

/**
 * The registry, cached in the writable cache dir for TTL. Returns
 * {wares: cleanArray} or {wares: [], error: string} — the tab renders both.
 */
function registry(): array
{
    $cfg = config();
    $file = __DIR__ . '/cache/wares-registry.json';
    if (is_file($file) && time() - (int)@filemtime($file) < $cfg['ttl']) {
        $cached = json_decode((string)file_get_contents($file), true);
        if (is_array($cached) && isset($cached['fetched'], $cached['wares'])) {
            return $cached;
        }
    }
    $raw = httpGet((string)$cfg['registryUrl']);
    if ($raw === null) {
        return ['wares' => [], 'error' => 'The Vitrine does not answer — check the host can reach '
            . (string)$cfg['registryUrl'] . '.'];
    }
    $reg = json_decode($raw, true);
    $clean = [];
    if (is_array($reg) && ($reg['schema'] ?? null) === 1 && is_array($reg['wares'] ?? null)) {
        foreach ($reg['wares'] as $ware) {
            if (!is_array($ware)
                || !is_string($ware['name'] ?? null)
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string)$ware['name']) !== 1
                || !in_array($ware['kind'] ?? '', ['plugin', 'template'], true)
                || !is_string($ware['path'] ?? null)
                || str_starts_with((string)$ware['path'], $ware['kind'] === 'plugin' ? 'plugins/' : 'templates/') === false
                || basename((string)$ware['path']) !== $ware['name']) {
                continue; // junk in the window display — taken off the shelf
            }
            $clean[] = [
                'kind' => (string)$ware['kind'],
                'name' => (string)$ware['name'],
                'title' => is_string($ware['title'] ?? null) ? $ware['title'] : (string)$ware['name'],
                'version' => is_string($ware['version'] ?? null) ? $ware['version'] : '0.0.0',
                'description' => is_string($ware['description'] ?? null) ? $ware['description'] : '',
                'requiresSchmarrn' => is_string($ware['requiresSchmarrn'] ?? null) ? $ware['requiresSchmarrn'] : '',
                'path' => (string)$ware['path'],
                'requires' => (array)array_values(array_filter(
                    (array)($ware['requires'] ?? []),
                    fn($dep) => is_string($dep) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $dep) === 1
                )),
            ];
        }
        $cached = ['fetched' => time(), 'wares' => $clean];
        @file_put_contents($file, json_encode($cached, JSON_UNESCAPED_SLASHES));
        return $cached;
    }
    return ['wares' => [], 'error' => 'The Vitrine\'s wares.json is not the schema this core understands.'];
}

/** A ware the host may have: {name: version} per kind, valid installs only. */
function installedVersions(): array
{
    $map = [];
    foreach (\Plugins\all() as $p) {
        if (!isset($p['error']) && is_string($p['version'] ?? null)) {
            $map[$p['name']] = (string)$p['version'];
        }
    }
    return $map;
}

/** A theme (template ware) the host may have: {name: version} off each theme.json. */
function installedThemeVersions(): array
{
    $map = [];
    foreach (glob(__DIR__ . '/templates/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $manifest = json_decode((string)@file_get_contents($dir . '/theme.json'), true);
        if (is_array($manifest) && is_string($manifest['version'] ?? null)) {
            $map[basename((string)$dir)] = (string)$manifest['version'];
        }
    }
    return $map;
}

/**
 * The admin's shelf view: every Vitrine ware with its local state —
 * installable | installed | update (+ the reachability error when the
 * registry itself is dark).
 */
function status(): array
{
    $reg = registry();
    if (isset($reg['error'])) {
        return ['wares' => [], 'error' => (string)$reg['error']];
    }
    $installed = [
        'plugin' => installedVersions(),
        'template' => installedThemeVersions(),
    ];
    $wares = [];
    foreach ($reg['wares'] as $ware) {
        $have = $installed[$ware['kind']][$ware['name']] ?? null;
        $state = 'installable';
        if ($have !== null && version_compare($ware['version'], $have) === 1) {
            $state = 'update';
        } elseif ($have !== null) {
            $state = 'installed';
        }
        // requirement law: a ware's deps must be INSTALLED on this host before
        // its install clears the lid — the shelf shows the gap, never hides it.
        $missing = [];
        foreach ($ware['requires'] as $dep) {
            if (!isset($installed['plugin'][$dep])) {
                $missing[] = $dep;
            }
        }
        $wares[] = [...$ware, 'state' => $state, 'installedVersion' => $have, 'missing' => $missing];
    }
    return ['wares' => $wares, 'host' => HOST_VERSION];
}

/** Fresh scratch room under the writable cache; caller deletes afterwards. */
function scratchDir(): string
{
    $dir = __DIR__ . '/cache/wares-' . bin2hex(random_bytes(4));
    if (!is_dir(dirname($dir))) {
        @mkdir(dirname($dir), 0775, true);
    }
    @mkdir($dir, 0775, true);
    return $dir;
}

/** Recursively part with a scratch room — best effort, it is cache dust. */
function partWith(string $dir): void
{
    if (!is_dir($dir) || !str_starts_with(realpath($dir) ?: '', __DIR__ . '/cache/wares-')) {
        return;
    }
    foreach (array_merge((array)glob($dir . '/*'), (array)glob($dir . '/.*')) as $item) {
        $item = (string)$item;
        if (basename($item) === '.' || basename($item) === '..') {
            continue;
        }
        is_dir($item) ? partWith($item) : @unlink($item);
    }
    @rmdir($dir);
}

/** Discernment rule 3: the host's floor vs the ware's wish. */
function hostSatisfies(string $requiresSchmarrn): bool
{
    return $requiresSchmarrn === ''
        || version_compare(HOST_VERSION, $requiresSchmarrn) >= 0;
}

/**
 * ONE install: registry lookup, discernment rules, packet zip, extract ONLY
 * the ware's own path, validate against the plugin/template contract, swap
 * into place (disable-first + rollback on upgrades). Refuses as JSON 4xx/5xx.
 */
function install(string $name): array
{
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
        \Router\refuse(400, 'That ware name is unbaked dough.');
    }
    $reg = registry();
    if (isset($reg['error'])) {
        \Router\refuse(502, (string)$reg['error']);
    }
    $ware = null;
    foreach ($reg['wares'] as $candidate) {
        if ($candidate['name'] === $name) {
            $ware = $candidate;
            break;
        }
    }
    if ($ware === null) {
        \Router\refuse(404, "'{$name}' is not on the Vitrine's shelf.");
    }
    if (!hostSatisfies($ware['requiresSchmarrn'])) {
        \Router\refuse(400, "'{$ware['title']}' wants Schmarrn {$ware['requiresSchmarrn']}+ — this host is " . HOST_VERSION . '.');
    }
    // The dependency wall (user-facing, before the packet is even fetched):
    // every required wares-plugin must stand installed on this host first.
    $have = installedVersions();
    $missing = array_values(array_filter($ware['requires'], fn($dep) => !isset($have[$dep])));
    if ($missing !== []) {
        \Router\refuse(409, "'{$ware['title']}' needs " . implode(', ', $missing)
            . " on this host first — install it from the shelf before this one.");
    }

    // ——— the packet: one zip of the whole branch, cached to a file ———
    if (!class_exists('ZipArchive') && !extension_loaded('phar')) {
        \Router\refuse(500, 'No unpacker in this kitchen — the host needs ext/zip or ext/phar.');
    }
    $scratch = scratchDir();
    $packetPath = $scratch . '/packet.zip';
    $bytes = httpGet((string)config()['packetUrl']);
    if ($bytes === null) {
        partWith($scratch);
        \Router\refuse(502, 'The Vitrine packet would not leave the depot — the install is off.');
    }
    if (@file_put_contents($packetPath, $bytes) === false || strlen($bytes) === 0) {
        partWith($scratch);
        \Router\refuse(500, 'The packet could not be held — the cache dir may be unwritable.');
    }

    // ——— extract: the ware's own path ONLY, names pre-checked as relative ———
    $rooms = extractWare($ware, $packetPath, $scratch);
    if ($rooms === null) {
        partWith($scratch);
        \Router\refuse(502, "'{$name}' is not inside the packet — the registry and the shelf drifted apart.");
    }
    if ($rooms === false) {
        partWith($scratch);
        \Router\refuse(500, 'The packet would not open — is the zip machinery intact?');
    }
    // The staging keeps the packet's own directory shape (branch dir prefix):
    // locate staging/<top>/<path>/, whatever the top is called.
    $staging = $scratch . '/staging';
    $wareDir = null;
    foreach (array_merge((array)glob($staging . '/*', GLOB_ONLYDIR), [$staging]) as $top) {
        $candidate = $top . '/' . $ware['path'];
        if (is_dir($candidate)) {
            $wareDir = $candidate;
            break;
        }
    }
    if ($wareDir === null) {
        partWith($scratch);
        \Router\refuse(502, "The packet unpacked, but '{$name}' was not among it — call it a bad pack job.");
    }

    // ——— the manifest answers for itself (rule 2: registry name === manifest name) ———
    if ($ware['kind'] === 'plugin') {
        $manifest = json_decode((string)@file_get_contents($wareDir . '/plugin.json'), true);
        $verdict = is_array($manifest) ? \Plugins\validate($name, $manifest) : 'manifest unreadable';
        if (!is_array($manifest)
            || ($manifest['name'] ?? null) !== $name
            || is_string($verdict)) {
            partWith($scratch);
            \Router\refuse(400, "'{$name}' arrived with a plugin.json this core refuses"
                . (is_string($verdict) ? ": {$verdict} — nothing installed." : ' — nothing installed.'));
        }
        $incoming = is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0';
    } else {
        $manifest = json_decode((string)@file_get_contents($wareDir . '/theme.json'), true);
        if (!is_array($manifest)) {
            partWith($scratch);
            \Router\refuse(400, "'{$name}' arrived without an intact theme.json — nothing installed.");
        }
        $incoming = is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0';
    }

    // ——— versions: overwrite only strictly newer; a manifest-less husk counts as less ———
    $home = __DIR__ . '/plugins';
    if ($ware['kind'] === 'template') {
        $home = __DIR__ . '/templates';
    }
    if (!is_dir($home)) {
        @mkdir($home, 0775, true);
    }
    $target = $home . '/' . $name;
    $have = $ware['kind'] === 'plugin' ? (installedVersions()[$name] ?? null) : (installedThemeVersions()[$name] ?? null);
    if ($have === null && is_dir($target)) {
        $oldManifest = $ware['kind'] === 'plugin'
            ? json_decode((string)@file_get_contents($target . '/plugin.json'), true)
            : json_decode((string)@file_get_contents($target . '/theme.json'), true);
        if (is_array($oldManifest) && is_string($oldManifest['version'] ?? null)) {
            $have = $oldManifest['version'];
        } else {
            @error_log("[schmarrn-wares] '{$name}' sits installed with an unreadable manifest — replacing it.");
        }
    }
    if ($have !== null && version_compare($incoming, (string)$have) !== 1) {
        partWith($scratch);
        \Router\refuse(409, $have === $incoming
            ? "'{$name}' {$incoming} is already installed."
            : "'{$name}' {$incoming} is OLDER than the installed {$have} — the Vitrine never derates.");
    }

    // ——— the swap: disable-first on upgrades, rollback on a failed rename ———
    $restored = false;
    if ($ware['kind'] === 'plugin' && ($have !== null || is_dir($target))) {
        mapWithout($name);
    }
    $backup = $scratch . '/backup-' . bin2hex(random_bytes(3));
    if (is_dir($target) && !@rename($target, $backup)) {
        partWith($scratch);
        \Router\refuse(500, 'The old shelf would not move — the swap is off, nothing installed.');
    }
    if (!@rename($wareDir, $target)) {
        if (is_dir($backup)) { // the rollback is the whole point of the backup
            @rename($backup, $target);
            $restored = true;
        }
        partWith($scratch);
        \Router\refuse(500, "The new shelf would not hang — the swap is off"
            . ($restored ? ', the old one stands, nothing lost.' : '.'));
    }
    if (is_dir($backup)) {
        partWith($backup);
    }
    // A swapped plugin's bytecode may outlive the swap on hosts that pin
    // validate_timestamps=0 (GUIDE's opcache law — one drop, guarded).
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($target . '/plugin.php', true);
    }
    lockRecord($name, $ware, $incoming);
    partWith($scratch);
    return ['ok' => true, 'name' => $name, 'kind' => $ware['kind'], 'version' => $incoming
        . ' — installed disabled; enable when you want it working.'];
}

// ——— the lock: what was installed from the Vitrine, with its requirements ———
// Data only (var_export into config, git-ignored): one honest box of provenance
// so the requirements (and their versions) stay trackable beyond the packet.

function lockFile(): string
{
    return __DIR__ . '/config/wares.lock.php';
}

function lockRead(): array
{
    $lock = is_file(lockFile()) ? (array)(require lockFile()) : [];
    return is_array($lock) ? $lock : [];
}

function lockWrite(array $lock): void
{
    $export = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($lock, true) . ";\n";
    $tmp = lockFile() . '.' . bin2hex(random_bytes(3)) . '.tmp';
    if (file_put_contents($tmp, $export) === false || !@rename($tmp, lockFile())) {
        @unlink($tmp);
        @error_log('[schmarrn-wares] the lock would not be written — provenance stays unrecorded.');
        return;
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(lockFile(), true);
    }
}

/** One successful install (fresh or upgrade) gets its box ticked. */
function lockRecord(string $name, array $ware, string $version): void
{
    $lock = lockRead();
    $lock[$name] = [
        'kind' => $ware['kind'],
        'title' => $ware['title'],
        'version' => $version,
        'requires' => array_values((array)$ware['requires']),
        'installedAt' => date('c'),
    ];
    lockWrite($lock);
}

/**
 * The requirements an INSTALLED plugin stands on, for the enable lid and the
 * plugin inventory. The lock is the bookkeeping truth (recorded at install);
 * the registry is the fallback for wares that slipped past the shelf.
 * Unknown hand-dropped installs read as dependency-free — their author never
 * declared any, in no ledger we keep.
 */
function requirements(string $name): array
{
    $lockEntry = lockRead()[$name] ?? null;
    if (is_array($lockEntry) && is_array($lockEntry['requires'] ?? null)) {
        return array_values((array)$lockEntry['requires']);
    }
    $reg = registry();
    if (isset($reg['error'])) {
        @error_log("[schmarrn-wares] enable lid could not reach the registry — '{$name}' reads as dependency-free.");
        return [];
    }
    foreach ($reg['wares'] as $ware) {
        if ($ware['name'] === $name) {
            return array_values((array)$ware['requires']);
        }
    }
    return [];
}

/** Pull one ware's own path out of the packet into $scratch/staging.
 *  Returns true, false when the packet will not open, null when the ware is
 *  absent. Entry names are checked as RELATIVE before extraction — no
 *  zeppelin windows, no climb-outs. The staging keeps the packet's own
 *  directory shape (branch dir prefix included) — locate via wareDirIn(). */
function extractWare(array $ware, string $packetPath, string $scratch): ?bool
{
    $engine = class_exists('ZipArchive') ? 'zip' : (extension_loaded('phar') ? 'phar' : null);
    if ($engine === null) {
        return false;
    }
    $names = [];
    try {
        if ($engine === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($packetPath, \ZipArchive::RDONLY) !== true) {
                return false;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                if ($entry !== '' && safeUnder($entry)) {
                    $names[] = $entry;
                }
            }
            $zip->close();
        } else {
            $phar = new \PharData($packetPath, \FilesystemIterator::SKIP_DOTS);
            $base = 'phar://' . str_replace('\\', '/', realpath($packetPath) ?: $packetPath) . '/';
            foreach (new \RecursiveIteratorIterator($phar) as $key => $file) {
                $entry = str_replace('\\', '/', (string)$key);
                if (str_starts_with($entry, $base)) {
                    $entry = substr($entry, strlen($base));
                }
                if ($entry !== '' && str_ends_with($entry, '/') === false && safeUnder($entry)) {
                    $names[] = $entry;
                }
            }
        }
    } catch (\Throwable) {
        return false;
    }

    // The packet wears one top dir (codeload: vitrine-main/) — match beyond it.
    $matching = array_values(array_filter($names, fn($n) =>
        preg_match('#^[^/]+/' . preg_quote($ware['path'], '#') . '(/|$)#', $n) === 1));
    if ($matching === []) {
        return null;
    }
    $staging = $scratch . '/staging';
    @mkdir($staging, 0775, true);
    try {
        if ($engine === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($packetPath, \ZipArchive::RDONLY) !== true) {
                return false;
            }
            $ok = $zip->extractTo($staging, $matching);
            $zip->close();
            return $ok ? true : false;
        }
        $phar = new \PharData($packetPath, \FilesystemIterator::SKIP_DOTS);
        return $phar->extractTo($staging, $matching, true) ? true : false;
    } catch (\Throwable) {
        return false;
    }
}

/** A zip/phar entry may name the ware's land only: no absolute, no climb, no dot tricks. */
function safeUnder(string $entry): bool
{
    $entry = str_replace('\\', '/', $entry);
    if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, "\0")) {
        return false;
    }
    foreach (explode('/', trim($entry, '/')) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }
    return true;
}

/** Remove one name from the enabled map (disable-first law on upgrades). */
function mapWithout(string $name): void
{
    $map = \Plugins\enabled();
    if (!isset($map[$name])) {
        return;
    }
    $clean = [];
    foreach ($map as $key => $on) {
        if ($key !== $name) {
            $clean[$key] = true;
        }
    }
    \Plugins\setState($clean);
}
