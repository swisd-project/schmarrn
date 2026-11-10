<?php

/**
 * 1. Relative path: bridge/wares.php
 * 2. Description: The Vitrine fetch + install + tomb engine (p6's wares slice).
 *                 The public Vitrine repo (swisd-project/vitrine) lists its wares
 *                 in one JSON (wares.json at the repo root — one GET, no token).
 *                 An update rides PER WARE: the trees API lists the ware's own
 *                 folder once, every file of it rides raw, and nothing else of
 *                 the branch travels — other wares stay on their shelf. The
 *                 announce gate fires BEFORE anything rides: only a strictly
 *                 newer wares.json version over the shelf's manifest moves files
 *                 (equal = "already installed"; the truth gate still answers
 *                 after the ride — a lying announce buys nothing). A dark
 *                 listing (rate limit, drift, truncation, a rogue name) degrades
 *                 to the old ONE zip of the whole branch (codeload packet), from
 *                 which ONLY <path>/ of the requested ware is extracted;
 *                 same staging shape, ride-agnostic the rest of the way.
 *                 Discernment before enabling: dir name
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
 *                 (admin + CSRF), POST /bridge/template/delete (admin + CSRF;
 *                 singular — templates/… is the theme assets' prefix). URLs are
 *                 overridable in config/wares.local.php (git-ignored) for local
 *                 tests — treeUrl '' turns the per-folder ride off (the packet
 *                 carries every install then; the local rig law stays no-GitHub).
 *                 THE CHAIN LAW (2026-11-07): the MANIFEST is the single
 *                 dependency truth ("name" / "name >=1.2", version_compare);
 *                 registry + lock only mirror. The install gate judges the
 *                 manifest that rode — presence at a satisfying version; the
 *                 truth gate names a shelf↔manifest drift instead of mumbling
 *                 "already installed". Deletion is refused while an effective
 *                 ware stands on the doomed one.
 * 3. Expects: bridge/plugins/ + bridge/templates/ homes; ZipArchive or phar
 *             (only as the packet fallback); allow_url_fopen for the breaches
 *             out (registry, trees API, raw files, packet zip).
 * 4. Provides: Wares\config/registry/folderIndex/stageFolder/status/install/
 *              removeTemplate (+ httpGet, scratch helpers, lockForget).
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Wares;

// Library files stand alone: each requires its dependencies explicitly.
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/plugins.php';
require_once __DIR__ . '/site.php'; // removeTemplate reads the live theme

/** The host floor: the core's own plain-text version (bridge/CORE-VERSION —
 *  one source of truth; the self-update carries its own new copy). */
function hostVersion(): string
{
    $raw = trim((string)@file_get_contents(__DIR__ . '/CORE-VERSION'));
    return $raw !== '' ? $raw : '1.0.0'; // an old shelf without the file keeps its floor
}
const REGISTRY_URL = 'https://raw.githubusercontent.com/swisd-project/vitrine/main/wares.json';
const PACKET_URL = 'https://codeload.github.com/swisd-project/vitrine/zip/heads/main';
// The per-folder ride: the trees API lists one ware's own files, raw carries each.
const TREE_URL = 'https://api.github.com/repos/swisd-project/vitrine/git/trees/main?recursive=1';
const RAW_URL = 'https://raw.githubusercontent.com/swisd-project/vitrine/main/';
const REGISTRY_TTL = 300; // s — the window stays honest, the fetch stays boring

/** The ride's own roof: file count and byte sum — a listing announcing beyond these is no trust. */
const FOLDER_FILE_CAP = 1000;
const FOLDER_BYTE_CAP = 104857600; // 100 MB

/** Defaults, overridable in config/wares.local.php (git-ignored; local tests). */
function config(): array
{
    $urls = ['registryUrl' => REGISTRY_URL, 'packetUrl' => PACKET_URL, 'treeUrl' => TREE_URL,
        'rawUrl' => RAW_URL, 'ttl' => REGISTRY_TTL];
    $file = __DIR__ . '/config/wares.local.php';
    if (is_file($file)) {
        $local = (array)(require $file);
        foreach (['registryUrl', 'packetUrl', 'treeUrl', 'rawUrl', 'ttl'] as $key) {
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
                || !safeUnder((string)$ware['path'])
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
                // the shelf image: the ware's own preview file in THIS repo
                // ("preview": "templates/catflash/preview.png") — the URL is
                // built from the raw base, so a mirror serves its own pictures.
                'preview' => (is_string($ware['preview'] ?? null) && safeUnder((string)$ware['preview']))
                    ? (string)$ware['preview']
                    : '',
                // the styles a THEME ware offers (the manifest's own words,
                // mirrored here so the shelf can show them before the install)
                'styles' => (array)array_values(array_filter(
                    (array)($ware['styles'] ?? []),
                    fn($s) => is_string($s) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s) === 1
                )),
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
        // the shelf image: the ware's own preview file, served from the raw
        // base (the mirror's own pictures for a mirrored registry)
        $preview = '';
        if (($ware['preview'] ?? '') !== '') {
            $preview = rtrim((string)config()['rawUrl'], '/') . '/' . ltrim((string)$ware['preview'], '/');
        }
        $wares[] = [...$ware, 'state' => $state, 'installedVersion' => $have, 'missing' => $missing, 'previewUrl' => $preview];
    }
    return ['wares' => $wares, 'host' => hostVersion()];
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

/**
 * The blanko hello-world (sidequest, 2026-11-07): a theme may ship blanko.md —
 * the starter page a fresh site is BORN with, so the front door never opens
 * onto a 404. Seeds ONLY into an empty tree: an existing site is never
 * surprise-planted. Returns the slug or null when nothing was planted.
 */
function seedBlanko(string $themeName): ?string
{
    if (preg_match('/^[a-z0-9-]+$/', $themeName) !== 1) {
        return null;
    }
    $file = __DIR__ . '/templates/' . $themeName . '/blanko.md';
    if (!is_file($file)) {
        return null; // the theme ships no blanko — its choice, lawful
    }
    $raw = (string)file_get_contents($file);
    if (trim($raw) === '') {
        return null;
    }
    $tree = \Store\listAll();
    if (!empty($tree['pages'])) {
        return null; // the site has pages — never surprise-plant
    }
    \Store\write('pages', 'index', $raw); // the index page IS the blanko (sidequest law)
    \Cache\drop('pages', 'index');
    return 'index';
}

function hostSatisfies(string $requiresSchmarrn): bool
{
    return $requiresSchmarrn === ''
        || version_compare(hostVersion(), $requiresSchmarrn) >= 0;
}

// ——— the per-folder ride: only the ware's own files ever travel ———

/**
 * ONE listing of the ware's own folder (the trees API answers path+size per
 * blob): [folderRelativePath => size|null]. null whenever the listing is
 * untrustable — dark, truncated, drifted, a rogue name, or the local law
 * turned it off (treeUrl '') — the caller then takes the packet as the ride.
 */
function folderIndex(array $ware): ?array
{
    $cfg = config();
    if ($cfg['treeUrl'] === '') {
        return null; // the local law turned the listing off
    }
    if (!safeUnder($ware['path'])) {
        return null; // the registry's own path reads unbaked — no trust
    }
    $raw = httpGet((string)$cfg['treeUrl']);
    if ($raw === null) {
        return null;
    }
    $tree = json_decode($raw, true);
    if (!is_array($tree) || !is_array($tree['tree'] ?? null)
        || ($tree['truncated'] ?? false) === true) {
        return null; // a truncated tree is a partial truth — never trusted
    }
    $prefix = $ware['path'] . '/';
    $files = [];
    foreach ($tree['tree'] as $entry) {
        if (!is_array($entry) || ($entry['type'] ?? '') !== 'blob') {
            continue;
        }
        $path = (string)($entry['path'] ?? '');
        if (!str_starts_with($path, $prefix)) {
            continue;
        }
        $rel = substr($path, strlen($prefix));
        if (!safeUnder($rel)) {
            return null; // one rogue name → the listing itself is untrustable
        }
        $files[$rel] = isset($entry['size']) && is_int($entry['size']) ? $entry['size'] : null;
    }
    return $files === [] ? null : $files; // an empty folder answer is a drift
}

/** One raw file of the ware landed at its folder place — false on any refusal. */
function fetchRaw(array $ware, string $rel, string $base): bool
{
    $bytes = httpGet((string)config()['rawUrl'] . $ware['path'] . '/' . $rel);
    if ($bytes === null) {
        return false;
    }
    $target = $base . '/' . $rel;
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }
    return @file_put_contents($target, $bytes) !== false;
}

/**
 * The ware's own folder staged under $scratch/staging — untouched shelf law:
 * names under $ware['path'] only (no climb), file sizes audited against the
 * listing, the whole ride beneath the roofs. null when the ride is dark —
 * the caller falls back to the packet, which stages the same shape.
 */
function stageFolder(array $ware, string $scratch): ?string
{
    $files = folderIndex($ware);
    if ($files === null || count($files) > FOLDER_FILE_CAP) {
        return null;
    }
    $staging = $scratch . '/staging';
    $base = $staging . '/' . $ware['path'];
    if (!is_dir($base) && !@mkdir($base, 0775, true)) {
        return null;
    }
    $total = 0;
    foreach ($files as $rel => $size) {
        if ($size !== null) {
            $total += $size;
            if ($total > FOLDER_BYTE_CAP) {
                return null; // the announced folder outweighs the roof
            }
        }
        if (!fetchRaw($ware, $rel, $base)) {
            return null;
        }
        // the arrival always answers for itself — announced size or counted truth
        $arrived = (int)@filesize($base . '/' . $rel);
        if ($size !== null && $arrived !== $size) {
            return null;
        }
        // a listing that hid the size (null) cannot dodge the roof either —
        // the arrival's real bytes join the sum.
        if ($size === null) {
            $total += $arrived;
            if ($total > FOLDER_BYTE_CAP) {
                return null;
            }
        }
    }
    return $staging;
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
        \Router\refuse(400, "'{$ware['title']}' wants Schmarrn {$ware['requiresSchmarrn']}+ — this host is " . hostVersion() . '.');
    }

    // ——— the announce gate, BEFORE anything rides: only a strictly newer
    // announcement moves files. The shelf's own manifest is the yardstick; an
    // unreadable husk counts as less (replaceable). The truth gate still
    // answers after the ride — a lying announce buys nothing. ———
    $home = __DIR__ . '/plugins';
    if ($ware['kind'] === 'template') {
        $home = __DIR__ . '/templates';
    }
    if (!is_dir($home)) {
        @mkdir($home, 0775, true);
    }
    $target = $home . '/' . $name;
    $installedVersion = $ware['kind'] === 'plugin'
        ? (installedVersions()[$name] ?? null) : (installedThemeVersions()[$name] ?? null);
    if ($installedVersion === null && is_dir($target)) {
        $oldManifest = $ware['kind'] === 'plugin'
            ? json_decode((string)@file_get_contents($target . '/plugin.json'), true)
            : json_decode((string)@file_get_contents($target . '/theme.json'), true);
        if (is_array($oldManifest) && is_string($oldManifest['version'] ?? null)) {
            $installedVersion = $oldManifest['version'];
        } else {
            @error_log("[schmarrn-wares] '{$name}' sits installed with an unreadable manifest — replacing it.");
        }
    }
    if ($installedVersion !== null
        && version_compare((string)$ware['version'], (string)$installedVersion) !== 1) {
        $installedVersion = (string)$installedVersion;
        \Router\refuse(409, (string)$ware['version'] === $installedVersion
            ? "'{$name}' {$installedVersion} is already installed."
            : "'{$name}' {$ware['version']} is OLDER than the installed {$installedVersion} — the Vitrine never derates.");
    }

    // ——— the ride: the ware's OWN folder — the trees API lists it once, every
    // file of it rides raw, nothing else travels. When the listing is dark
    // (rate limit, drift, truncation, a rogue name), the old one-zip packet
    // carries the whole branch as the fallback ride. ———
    $scratch = scratchDir();
    // ONE KNIFE AT A TIME (2026-11-07, the audit): two simultaneous installs
    // race the same shelf dir — the swap is atomic, the double-swap is not.
    // Same named-flock law the core update rides under.
    $knife = @fopen(__DIR__ . '/cache/wares.lock', 'c');
    if ($knife === false || !@flock($knife, LOCK_EX | LOCK_NB)) {
        if (is_resource($knife)) {
            @fclose($knife);
        }
        partWith($scratch);
        \Router\refuse(409, 'One knife at a time — a wares install is already on the table.');
    }
    // the knife rest: the blade unlocks and closes, the FILE stays (audit U3,
    // fixed 2026-11-07 — the same laundering law the core lock learned): an
    // unlinked lock file hands the next cook a fresh inode and a false mutex.
    // Zero-byte dust in a writable cache is the honest price.
    register_shutdown_function(static function () use ($knife): void {
        if (is_resource($knife)) {
            @flock($knife, LOCK_UN);
            @fclose($knife);
        }
    });
    $wareDir = null;
    $staging = stageFolder($ware, $scratch);
    if ($staging !== null) {
        $wareDir = $staging . '/' . $ware['path'];
        if (!is_dir($wareDir)) {
            $wareDir = null; // a listing lie — the packet may still tell the truth
        }
    }
    if ($wareDir === null) {
        // ——— fallback: one zip of the whole branch, cached to a file ———
        if (!class_exists('ZipArchive') && !extension_loaded('phar')) {
            partWith($scratch);
            \Router\refuse(500, 'No unpacker in this kitchen — the host needs ext/zip or ext/phar, or the folder listing to answer.');
        }
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
        // ——— the code lid (2026-11-10, user law): the ware's CODE must
        // resolve too — a mangled identifier (a duplicated letter) survives
        // the grammar but dies at the call site, hours later, on the host.
        // The core lides the ware: read the code here, and a ware whose code
        // cannot resolve never lands. ———
        $codeFault = \Plugins\codeCheck($wareDir);
        if ($codeFault !== null) {
            partWith($scratch);
            \Router\refuse(400, "'{$name}' arrived with code this core refuses: {$codeFault} Nothing installed.");
        }
    } else {
        $manifest = json_decode((string)@file_get_contents($wareDir . '/theme.json'), true);
        if (!is_array($manifest)) {
            partWith($scratch);
            \Router\refuse(400, "'{$name}' arrived without an intact theme.json — nothing installed.");
        }
        $incoming = is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0';
    }

    // ——— the truth gate: the manifest answers for itself, a lying announce
    // dies here — overwritten only when strictly newer than the shelf. ———
    if ($installedVersion !== null && version_compare($incoming, (string)$installedVersion) !== 1) {
        partWith($scratch);
        $drift = (string)($ware['version'] ?? '') !== $incoming
            ? " — and note the drift: the Vitrine's shelf announces {$ware['version']} while the ware's own manifest says {$incoming}. The manifest is the truth; fix the shelf."
            : '';
        \Router\refuse(409, $installedVersion === $incoming
            ? "'{$name}' {$incoming} is already installed.{$drift}"
            : "'{$name}' {$incoming} is OLDER than the installed {$installedVersion} — the Vitrine never derates.{$drift}");
    }

    // ——— the chain gate (the Chain Law, 2026-11-07): the MANIFEST answers for
    // its own dependencies — the registry's memory and the lock's bookkeeping
    // are not the truth, the ware is. Installed at a satisfying version, or
    // nothing rides. Effectiveness is demanded later, at the enable switch
    // and at activation — install asks presence, the running state asks life. ———
    $requiresParsed = \Chain\parse($manifest['requires'] ?? []);
    if ($requiresParsed === null) {
        partWith($scratch);
        \Router\refuse(400, "'{$name}' declares requirements this core cannot read — entries are \"name\" or \"name >=1.2\". Nothing installed.");
    }
    foreach ($requiresParsed as $req) {
        if ($req['name'] === $name) {
            partWith($scratch);
            \Router\refuse(400, "'{$name}' does not require itself — chains start elsewhere.");
        }
    }
    $versions = installedVersions();
    foreach (installedThemeVersions() as $themeName => $themeVersion) {
        $versions[$themeName] = $themeVersion;
    }
    $unmet = \Chain\unmet($requiresParsed, \Chain\pool($versions, array_keys($versions)));
    if ($unmet !== []) {
        partWith($scratch);
        \Router\refuse(409, "'{$name}' stands on " . implode(', ', $unmet)
            . ' — install ' . (count($unmet) === 1 ? 'it' : 'them')
            . ' from the shelf first, then ride again.');
    }

    // ——— the swap: disable-first on upgrades, rollback on a failed rename ———
    // The Chain Law at the knife: a plugin leaving the shelf (even for an
    // upgrade) cannot strand dependents as orphan intent — cascade-disable
    // everything that stands on it, transitively, before the swap.
    $restored = false;
    if ($ware['kind'] === 'plugin' && ($installedVersion !== null || is_dir($target))) {
        mapWithoutCascade($name);
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
    lockRecord($name, $ware, $incoming, array_map(fn($req) => \Chain\describe($req), $requiresParsed));
    partWith($scratch);
    // the shelf changed mid-request — a plugin landing must not be answered
    // from the scan memo's memory of the old shelf (the optimization pass)
    if (($ware['kind'] ?? '') === 'plugin') {
        \Plugins\forget();
    }
    return ['ok' => true, 'name' => $name, 'kind' => $ware['kind'], 'version' => $incoming
        . ' — installed disabled; enable when you want it working.'];
}

// ——— the lock: what was installed from the Vitrine, with its requirements ———
// Data only (var_export into config, git-ignored): one honest box of provenance.
// Since the Chain Law the lock is bookkeeping, never the enforced truth — the
// manifest carries the dependencies; the lock remembers who installed what when.

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

/** One successful install (fresh or upgrade) gets its box ticked — with the
 *  requirements THE MANIFEST declared (the truth that rode), not the shelf's
 *  claim about them. */
function lockRecord(string $name, array $ware, string $version, array $requires): void
{
    $lock = lockRead();
    $lock[$name] = [
        'kind' => $ware['kind'],
        'title' => $ware['title'],
        'version' => $version,
        'requires' => array_values($requires),
        'installedAt' => date('c'),
    ];
    lockWrite($lock);
}

/** A removed install loses its provenance box — the lock stays the truth. */
function lockForget(string $name): void
{
    $lock = lockRead();
    if (!isset($lock[$name])) {
        return;
    }
    unset($lock[$name]);
    lockWrite($lock);
}

/**
 * ONE deletion of an installed PLUGIN (2026-11-10, user order): the shelf
 * loses the folder, the lock forgets it, the switches drop it. NO plugin is
 * undeletable — "the house default is undeletable" is a TEMPLATE law (the
 * site must never stand on nothing); a plugin is decoration, and the
 * preinstalled default plugin (hello) is deletable like any other.
 * Atomicity: the shelf dir is renamed into the writable cache first (never a
 * half-deleted plugin on the shelf), then dusted. Refuses in 4xx JSON.
 */
function removePlugin(string $name): array
{
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
        \Router\refuse(400, 'That plugin name is unbaked dough.');
    }
    $dir = __DIR__ . '/plugins/' . $name;
    if (!is_file($dir . '/plugin.json')) {
        \Router\refuse(404, "'{$name}' is not an installed plugin.");
    }
    // ——— the chain law: a ware an EFFECTIVE ware stands on is not deleted ———
    foreach (\Plugins\all() as $p) {
        if (isset($p['error']) || empty($p['effective'])) {
            continue;
        }
        foreach ($p['requiresParsed'] as $req) {
            if ($req['name'] === $name) {
                \Router\refuse(409, "'{$p['title']}' stands on '{$name}' — disable it before the deletion.");
            }
        }
    }
    $tomb = scratchDir();
    if (!@rename($dir, $tomb . '/' . $name)) {
        partWith($tomb);
        \Router\refuse(500, "The shelf would not let '{$name}' go — the deletion is off, nothing removed.");
    }
    partWith($tomb); // best effort — leftover dust is cache's, never the shelf's
    lockForget($name);
    // The switches forget it too — a ghost intent for a plugin that no longer
    // exists is a lie the state file must not tell.
    \Plugins\forgetState($name);
    return ['ok' => true, 'name' => $name];
}

/**
 * ONE deletion of an installed template (theme): the ACTIVE theme is
 * untouchable — the site stands on it. Atomicity: the shelf dir is renamed
 * into the writable cache first (never a half-deleted theme on the shelf),
 * then dusted; its provenance box leaves the lock. Refuses in 4xx JSON.
 */
function removeTemplate(string $name): array
{
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
        \Router\refuse(400, 'That template name is unbaked dough.');
    }
    // THE HOUSE FALLBACK (2026-11-09, user order): the core's default theme
    // is undeletable — it is the floor every broken theme lands on.
    if ($name === \Site\DEFAULTS['theme']) {
        \Router\refuse(409, "'{$name}' is the house default — the fallback when a theme fails. It cannot be deleted.");
    }
    if (\Site\load()['theme'] === $name) {
        \Router\refuse(409, "'{$name}' is the live theme — activate another template before deleting it.");
    }
    // ——— the chain law: a ware an EFFECTIVE ware stands on is not deleted ———
    foreach (\Plugins\all() as $p) {
        if (isset($p['error']) || empty($p['effective'])) {
            continue;
        }
        foreach ($p['requiresParsed'] as $req) {
            if ($req['name'] === $name) {
                \Router\refuse(409, "'{$p['title']}' stands on '{$name}' — disable it before the deletion.");
            }
        }
    }
    foreach (glob(__DIR__ . '/templates/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $dependent = basename($dir);
        if ($dependent === $name) {
            continue;
        }
        $manifest = json_decode((string)@file_get_contents($dir . '/theme.json'), true);
        $reqs = is_array($manifest) ? \Chain\parse($manifest['requires'] ?? []) : null;
        if ($reqs === null) {
            continue; // unreadable claims make no dependents
        }
        foreach ($reqs as $req) {
            if ($req['name'] === $name) {
                \Router\refuse(409, "The template '{$dependent}' stands on '{$name}' — delete it first.");
            }
        }
    }
    // The last template stands (sidequest, 2026-11-07): a site is never themeless.
    $remaining = 0;
    foreach ((array)scandir(__DIR__ . '/templates') ?: [] as $dir) {
        if ($dir !== $name && !str_starts_with((string)$dir, '.')
            && is_dir(__DIR__ . '/templates/' . $dir)
            && is_file(__DIR__ . '/templates/' . $dir . '/theme.json')) {
            $remaining++;
        }
    }
    if ($remaining === 0) {
        \Router\refuse(409, "The last template stands — a site is never themeless. Install another before '{$name}' leaves.");
    }
    $dir = __DIR__ . '/templates/' . $name;
    if (!is_file($dir . '/theme.json')) {
        \Router\refuse(404, "'{$name}' is not an installed template.");
    }
    $tomb = scratchDir();
    if (!@rename($dir, $tomb . '/' . $name)) {
        partWith($tomb);
        \Router\refuse(500, "The shelf would not let '{$name}' go — the deletion is off, nothing removed.");
    }
    partWith($tomb); // best effort — leftover dust is cache's, never the shelf's
    lockForget($name);
    return ['ok' => true, 'name' => $name];
}

/**
 * The requirements an installed ware stands on — RETIRED by the Chain Law
 * (2026-11-07): the manifest is the single truth, read live from disk, so a
 * lost lock line or a rewritten registry can never evaporate a dependency.
 * The plugin inventory reads Plugins\all() (requires + effective + blockedBy);
 * the activation gate reads the theme.json in hand.
 */

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

/** Disable-first, cascaded: a plugin leaving the shelf (upgrade/delete path)
 *  takes every dependent's intent with it — direct and transitive — so no ware
 *  stays switched-on for a chain that cannot stand. The manifests answer who
 *  stands on whom; 64 rounds caps a ring (the chain never promotes a ring). */
function mapWithoutCascade(string $name): void
{
    $doomed = [$name => true];
    for ($round = 0; $round < 64; $round++) {
        $grew = false;
        foreach (\Plugins\all() as $plugin) {
            if (isset($plugin['error']) || isset($doomed[$plugin['name']])) {
                continue;
            }
            foreach ((array)($plugin['requiresParsed'] ?? []) as $req) {
                if (is_array($req) && isset($doomed[(string)($req['name'] ?? '')])) {
                    $doomed[(string)$plugin['name']] = true;
                    $grew = true;
                    break;
                }
            }
        }
        if (!$grew) {
            break;
        }
    }
    $map = \Plugins\enabled();
    $clean = [];
    $changed = false;
    foreach ($map as $key => $on) {
        if (!isset($doomed[$key])) {
            $clean[$key] = true;
        } else {
            $changed = true;
        }
    }
    if ($changed || isset($map[$name])) {
        \Plugins\setState($clean);
    }
}
