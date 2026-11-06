<?php

/**
 * 1. Relative path: bridge/core.php
 * 2. Description: The core's self-update engine — the CMS updates ITSELF from
 *                 GitHub versions. The floor lives in bridge/CORE-VERSION
 *                 (plain text, swap-safe — every core update carries its own
 *                 new copy); the newest version is announced by GitHub
 *                 (releases/latest first, the tag list as the fallback — one
 *                 GET each, no token). ONE strict-newer gate BEFORE anything
 *                 rides; then the packet — the RELEASE's own zip asset when
 *                 the release ships one (the only way a built dashboard,
 *                 build/, can travel), the tag's codeload zip as the floor —
 *                 is staged under a WHITELIST: only the core's land ever
 *                 swaps — index.php, .htaccess, bridge/*.php modules, the
 *                 config's non-LOCAL defaults, the wholesale dirs (build/,
 *                 _/, bridge/assets/, bridge/lib/ — shipped or not, tag zips
 *                 carry no build/) and pagetypes/ shells under the MERGE LAW: NEW
 *                 shells land, a dressed one is never overwritten. Everything
 *                 a user owns — plugins/, templates/, config/*.local.php,
 *                 wares.lock.php, content, the cache — the packet cannot
 *                 touch, resting on path-law alone. THE BLANKO WINDOW: while
 *                 the knife is out (a marker in the writable cache), the
 *                 front controllers hand every load except the operation
 *                 itself a blank plate — a first load during the swap sees a
 *                 blank page, never a half-swapped 404. Each replaced thing
 *                 is backed into the scratch; one break rolls the whole lot
 *                 back and answers 5xx. A named flock keeps it one knife at a
 *                 time; a shutdown hook guarantees the marker leaves even on
 *                 a hard stop. The truly broken update is never a dead site —
 *                 the rollback is the whole point of the backups.
 * 3. Expects: bridge/CORE-VERSION; api.github.com + codeload.github.com
 *             (allow_url_fopen); ZipArchive or phar; writable cache/.
 * 4. Provides: Core\version/announce/update/blanko/blankoNote (+ httpGet,
 *              scratch tomb, isCore/stageCore/swap/placeSimple/allPhp).
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/router.php';

const RELEASES_URL = 'https://api.github.com/repos/swisd-project/schmarrn/releases/latest';
const TAGS_URL = 'https://api.github.com/repos/swisd-project/schmarrn/tags';
const PACKET_URL = 'https://codeload.github.com/swisd-project/schmarrn/zip/refs/tags/';
const VERSION_FILE = __DIR__ . '/CORE-VERSION';
const BLANK_FILE = __DIR__ . '/cache/core-blank';
const LOCAL_PACKET = __DIR__ . '/cache/core-packet.zip'; // a locally parked update
const FILE_CAP = 3000; // the core's own roofs: entries and bytes
const BYTE_CAP = 104857600; // 100 MB

/** One breach out: GET with a timeout, no reuse (the wares law, home copy). */
function httpGet(string $url): ?string
{
    $context = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'schmarrn-core/1.0'],
        'https' => ['timeout' => 20, 'user_agent' => 'schmarrn-core/1.0']]);
    $bytes = @file_get_contents($url, false, $context);
    return $bytes === false ? null : $bytes;
}

/** A zip/phar entry name may name the repo only: no absolute, no climb, no dot tricks. */
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

/** Fresh scratch under the writable cache; the tomb dusts it afterwards. */
function scratchDir(): string
{
    $dir = __DIR__ . '/cache/core-' . bin2hex(random_bytes(4));
    if (!is_dir(dirname($dir))) {
        @mkdir(dirname($dir), 0775, true);
    }
    @mkdir($dir, 0775, true);
    return $dir;
}

/** Recursively part with a scratch — best effort, it is cache dust. */
function partWith(string $dir): void
{
    if (!is_dir($dir) || !str_starts_with(realpath($dir) ?: '', __DIR__ . '/cache/core-')) {
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

/** The shelf's own floor: plain text off the version file (every update carries a new one). */
function version(): string
{
    $raw = trim((string)@file_get_contents(VERSION_FILE));
    return $raw !== '' ? $raw : '1.0.0'; // an old shelf without the file keeps its floor
}

/**
 * The release's own packet URL: its zip asset — the only place a built
 * dashboard (build/) can travel in. A schmarrn-named zip wins; any other zip
 * is the floor; none → null (the codeload zipball takes over).
 */
function assetIn(array $release): ?string
{
    $asset = null;
    foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $a) {
        $name = is_string($a['name'] ?? null) ? strtolower((string)$a['name']) : '';
        $url = is_string($a['browser_download_url'] ?? null) ? (string)$a['browser_download_url'] : '';
        if ($url === '' || !str_ends_with($name, '.zip')) {
            continue; // not a packet — checksums, sigs, notes stay out
        }
        if ($asset === null || str_contains($name, 'schmarrn')) {
            $asset = $url;
        }
    }
    return $asset;
}

/**
 * The newest version GitHub announces: a release first (releases/latest —
 * drafts and prereleases filtered by GitHub itself), the tag list as the
 * fallback (highest semver of the names). The PACKET rides the release's own
 * zip ASSET when the release ships one — the asset is the only place a built
 * dashboard (build/) can travel in; a tag's codeload zip is source alone.
 * latest null = the source is dark.
 */
function announce(): array
{
    $tag = null;
    $asset = null; // the release's own packet URL, when it ships one
    $raw = httpGet(RELEASES_URL);
    if ($raw !== null) {
        $release = json_decode((string)$raw, true);
        if (is_array($release) && is_string($release['tag_name'] ?? null)) {
            $tag = (string)$release['tag_name'];
            $asset = assetIn($release);
        }
    } else {
        // the first GET went dark — the plain tag list may still answer
        $raw = httpGet(TAGS_URL);
        if ($raw !== null) {
            $list = json_decode((string)$raw, true);
            if (is_array($list)) {
                foreach ($list as $entry) {
                    $name = is_string($entry['name'] ?? null) ? (string)$entry['name'] : '';
                    if (preg_match('/^v?(\d+(?:\.\d+){0,3})$/', $name) !== 1) {
                        continue; // junk in the tag hat — not a version
                    }
                    if ($tag === null || version_compare(ltrim($name, 'v'), ltrim($tag, 'v')) === 1) {
                        $tag = $name;
                    }
                }
            }
        }
    }
    if ($tag === null) {
        return ['latest' => null, 'packet' => null, 'error' => 'GitHub does not answer — the core\'s newest version is unknown from here.'];
    }
    return [
        'latest' => $tag,
        'packet' => $asset !== null ? $asset : PACKET_URL . $tag, // asset zip (with the dashboard) first, source zipball as the floor
        'error' => '',
    ];
}

/**
 * Core by law — the packet's file at this repo-relative path may swap, or
 * not. Core: index.php, .htaccess, the version file, every top-level bridge
 * module, the config's non-LOCAL defaults, the wholesale dirs (build/,
 * bridge/assets/, bridge/lib/) and the pagetypes/ shells (merge law).
 * Everything else — plugins/, templates/, *.local.php, the lock, content,
 * the cache — is never core, by path alone.
 */
function isCore(string $rel): bool
{
    if ($rel === 'index.php' || $rel === '.htaccess' || $rel === 'bridge/.htaccess'
        || $rel === 'bridge/CORE-VERSION') {
        return true;
    }
    if (preg_match('#^bridge/[a-z0-9-]+\.php$#', $rel) === 1) {
        return true; // every top-level bridge module answers for itself
    }
    if (str_starts_with($rel, 'bridge/config/')) {
        return str_ends_with($rel, '.php') && !str_ends_with($rel, '.local.php');
    }
    if (str_starts_with($rel, 'build/') || str_starts_with($rel, '_/')
        || str_starts_with($rel, 'bridge/assets/') || str_starts_with($rel, 'bridge/lib/')) {
        return true; // the wholesale dirs — they walk in whole or not at all
    }
    if (str_starts_with($rel, 'pagetypes/') && str_ends_with($rel, '.md')) {
        return true; // merge law — NEW shells land, dressed ones stay
    }
    return false;
}

/** Strip the packet's own top dir — the repo-relative name, '' outside of it. */
function repoRelative(string $entry): string
{
    if (!safeUnder($entry)) {
        return '';
    }
    $slash = strpos($entry, '/');
    if ($slash === false) {
        return ''; // the packet wears one top dir — a first-level stray is none
    }
    return substr($entry, $slash + 1);
}

/**
 * Stage the packet's core land: ONLY isCore() entries cross, entry names
 * checked as relative, the staging carries repo-relative shape
 * (staging/bridge/…). Returns [staging|false|null, entries, bytes] — false
 * when the packet will not open, null when its core land is absent or a roof
 * (count, bytes) is jumped.
 */
function stageCore(string $packetPath, string $scratch): array
{
    $engine = class_exists('ZipArchive') ? 'zip' : (extension_loaded('phar') ? 'phar' : null);
    if ($engine === null) {
        return [false, 0, 0];
    }
    $staging = $scratch . '/staging';
    $names = [];
    $bytes = 0;
    try {
        if ($engine === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($packetPath, \ZipArchive::RDONLY) !== true) {
                return [false, 0, 0];
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                $rel = repoRelative($entry);
                if ($rel === '' || !isCore($rel)) {
                    continue; // the whitelist — anything else stays on the outside
                }
                $stat = $zip->statIndex($i);
                $bytes += (int)($stat['size'] ?? 0);
                $names[] = $entry;
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
                $rel = repoRelative($entry);
                if ($rel === '' || !isCore($rel)) {
                    continue;
                }
                $bytes += (int)$file->getSize();
                $names[] = $entry;
            }
        }
    } catch (\Throwable) {
        return [false, 0, 0];
    }
    if ($names === [] || count($names) > FILE_CAP || $bytes > BYTE_CAP) {
        return [null, count($names), $bytes]; // no core ride, or a roof jumped
    }
    @mkdir($staging, 0775, true);
    try {
        if ($engine === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($packetPath, \ZipArchive::RDONLY) !== true) {
                return [false, 0, 0];
            }
            $ok = $zip->extractTo($staging, $names);
            $zip->close();
            return $ok ? [$staging, count($names), $bytes] : [false, 0, 0];
        }
        $phar = new \PharData($packetPath, \FilesystemIterator::SKIP_DOTS);
        return $phar->extractTo($staging, $names, true)
            ? [$staging, count($names), $bytes] : [false, 0, 0];
    } catch (\Throwable) {
        return [false, 0, 0];
    }
}

/** One placement: the old moves into the scratch, the new takes the spot. */
function placeSimple(string $rel, string $src, string $root, string $scratch, array &$backups, array &$files): bool
{
    $target = $root . '/' . $rel;
    $parent = dirname($target);
    if (!is_dir($parent) && !@mkdir($parent, 0775, true)) {
        return false;
    }
    $old = $scratch . '/backup-' . md5($rel);
    if (is_file($target)) {
        if (!@rename($target, $old)) {
            return false; // the old would not move — nothing has swapped
        }
        $backups[$rel] = $old;
    }
    if (!@rename($src, $target)) {
        if (isset($backups[$rel])) {
            @rename($backups[$rel], $target); // the spot stays warm, rollback owns the rest
        }
        return false;
    }
    $files[] = $target;
    return true;
}

/** Every .php file under a wholesale dir — the opcache drop walks them. */
function allPhp(string $dir): array
{
    $out = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_ends_with((string)$file, '.php')) {
            $out[] = (string)$file;
        }
    }
    return $out;
}

/**
 * THE SWAP: wholesale dirs first (whole dir in, old whole out), NEW pagetypes
 * land under the merge law, then the named core files. Every replaced thing
 * is backed into $scratch keyed by its repo-relative name; one break rolls
 * everything already swapped back. Returns [files[], addedPagetypes,
 * error|null] — error answered only AFTER a full restore.
 */
function swap(string $staging, string $scratch): array
{
    $root = dirname(__DIR__);
    // the packet wears ONE top dir (codeload: schmarrn-v1.2.3/) — the repo-
    // relative land begins under it, whatever the release's name is
    $staged = $staging;
    foreach ((array)glob($staging . '/*', GLOB_ONLYDIR) ?: [] as $candidate) {
        $staged = (string)$candidate;
        break;
    }
    $backups = []; // repo-relative => the old's scratch place
    $files = []; // every swapped target — the opcache drops walk this
    $restore = static function () use (&$backups, $root): void {
        foreach (array_reverse(array_keys($backups)) as $rel) {
            $target = $root . '/' . $rel;
            $old = (string)$backups[$rel];
            if (is_file($target)) {
                @unlink($target);
            }
            @rename($old, $target);
        }
    };

    // ——— wholesale dirs: the packet may ship none, some, or all ———
    foreach (['build', '_', 'bridge/assets', 'bridge/lib'] as $dir) {
        $src = $staged . '/' . $dir;
        if (!is_dir($src)) {
            continue;
        }
        $target = $root . '/' . $dir;
        $old = $scratch . '/dir-backup-' . str_replace('/', '-', $dir);
        if (is_dir($target) && !@rename($target, $old)) {
            $restore();
            return [[], 0, "the old '{$dir}' would not move — the swap is off, nothing lost"];
        }
        if (!@rename($src, $target)) {
            if (is_dir($old)) {
                @rename($old, $target);
            }
            $restore();
            return [[], 0, "the new '{$dir}' would not hang — the swap is off, nothing lost"];
        }
        array_push($files, ...allPhp($target));
    }

    // ——— pagetypes: the merge law — a NEW shell lands, a dressed shell stays ———
    $pagetypes = 0;
    foreach ((array)glob($staged . '/pagetypes/*.md') ?: [] as $src) {
        $rel = 'pagetypes/' . basename((string)$src);
        if (!is_file($root . '/' . $rel) && placeSimple($rel, (string)$src, $root, $scratch, $backups, $files)) {
            $pagetypes++;
        }
    }

    // ——— the named core files: bridge modules, config defaults, the version ———
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staged, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr(str_replace('\\', '/', (string)$file), strlen($staged) + 1);
        if (!isCore($rel)
            || str_starts_with($rel, 'build/') || str_starts_with($rel, 'bridge/assets/')
            || str_starts_with($rel, 'bridge/lib/') || str_starts_with($rel, 'pagetypes/')) {
            continue; // dirs already walked whole; the shells merged above
        }
        if (!placeSimple($rel, (string)$file, $root, $scratch, $backups, $files)) {
            $restore();
            return [[], 0, "the swap broke at '{$rel}' — the rollback is done, the old core stands"];
        }
    }
    return [$files, $pagetypes, null];
}

// ——— the blanko window: the marker is the knife's visibility ———

/** The blank plate itself: no theme, no assets, no route — nothing that could be half-swapped. */
function blanko(): string
{
    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<meta name=\"robots\" content=\"noindex\">\n<title>Updating</title>\n"
        . "<style>html,body{margin:0;padding:0;height:100%}</style>\n</head>\n<body></body>\n</html>\n";
}

/** The API-side refusal note (JSON) for the window. */
function blankoNote(): string
{
    return 'The core is under the knife — updating. A blank plate until the knife leaves.';
}

function blankoOn(): void
{
    if (!is_dir(dirname(BLANK_FILE))) {
        @mkdir(dirname(BLANK_FILE), 0775, true);
    }
    @file_put_contents(BLANK_FILE, date('c'));
}

function blankoOff(): void
{
    @unlink(BLANK_FILE);
}

function blankKnives(): bool // a blade out, any swap loud? — the marker's answer
{
    return is_file(BLANK_FILE);
}

/**
 * The version a parked packet announces: the bridge/CORE-VERSION entry off its
 * top dir. null when nothing is parked or the packet will not open or carries
 * no version — the park preview already answers before it can be ridden.
 */
function peekPacket(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $engine = class_exists('ZipArchive') ? 'zip' : (extension_loaded('phar') ? 'phar' : null);
    if ($engine === null) {
        return null;
    }
    try {
        $version = null;
        if ($engine === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                return null;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rel = repoRelative((string)$zip->getNameIndex($i));
                if ($rel === 'bridge/CORE-VERSION') {
                    $version = trim((string)$zip->getFromIndex($i));
                    break;
                }
            }
            $zip->close();
        } else {
            // phar reads survive phar.readonly — the same law the packet fallback rides
            $phar = new \PharData($path, \FilesystemIterator::SKIP_DOTS);
            $base = 'phar://' . str_replace('\\', '/', realpath($path) ?: $path) . '/';
            foreach (new \RecursiveIteratorIterator($phar) as $key => $file) {
                $entry = str_replace('\\', '/', (string)$key);
                if (str_starts_with($entry, $base)) {
                    $entry = substr($entry, strlen($base));
                }
                if (repoRelative($entry) === 'bridge/CORE-VERSION') {
                    $version = trim((string)file_get_contents((string)$key));
                    break;
                }
            }
        }
        return $version !== null && $version !== '' ? $version : null;
    } catch (\Throwable) {
        return null;
    }
}

/** Park a LOCAL update packet (admin upload): zip law, roof, a core-land audit. */
function parkPacket(): array
{
    if (!class_exists('ZipArchive') && !extension_loaded('phar')) {
        \Router\refuse(500, 'No unpacker in this kitchen — the host needs ext/zip or ext/phar.');
    }
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_string($upload['tmp_name']) || (string)$upload['name'] === '') {
        \Router\refuse(400, 'You promised a packet and brought an empty plate — no file in the upload.');
    }
    if (str_ends_with(strtolower((string)$upload['name']), '.zip') === false) {
        \Router\refuse(400, 'Only a .zip rides this door — a codeload packet, renamed to taste is fine.');
    }
    if ((int)$upload['size'] > BYTE_CAP) {
        \Router\refuse(400, 'The packet outweighs the shelf— ' . BYTE_CAP . ' bytes is the roof.');
    }
    $parked = LOCAL_PACKET;
    $parking = $parked . '.' . bin2hex(random_bytes(3)) . '.tmp';
    if (!@move_uploaded_file((string)$upload['tmp_name'], $parking) || !@rename($parking, $parked)) {
        @unlink($parking);
        \Router\refuse(500, 'The packet would not be parked — the cache dir may be unwritable.');
    }
    @chmod($parked, 0644);
    $version = peekPacket($parked);
    if ($version === null) {
        @unlink($parked);
        \Router\refuse(400, 'The packet carries no core land — no bridge/CORE-VERSION among it. Nothing parked.');
    }
    return ['ok' => true, 'version' => $version, 'bytes' => (int)@filesize($parked)];
}

/**
 * ONE self-update, served from ONE of two houses: 'hub' rides GitHub's newest
 * announce (releases/tags), 'local' rides the parked packet — the outage
 * handle: a firewalled host or a dead hub still gets its knife, and an EQUAL
 * local version stays lawful (a repair ride). OLDER never rides, from either
 * house. From here the laws are identical: the strict gate, the whitelist
 * staging, the blanko window over the swap (rollback on any break), the
 * opcache drops. A successful local ride consumes the packet.
 */
function update(string $source = 'hub'): array
{
    if ($source !== 'hub' && $source !== 'local') {
        \Router\refuse(400, 'The update rides from "hub" or "local" — that is the whole menu.');
    }
    $from = version(); // the floor before the ride — the gates and the note read it
    // no scratch before the gates — a refusal must not even leave empty dust
    if ($source === 'local') {
        if (!is_file(LOCAL_PACKET)) {
            \Router\refuse(409, 'No packet is parked — upload the update zip first, then the knife comes.');
        }
        $ver = peekPacket(LOCAL_PACKET);
        if ($ver === null) {
            \Router\refuse(502, 'The parked packet carries no core land any more — park it again.');
        }
        if (version_compare($ver, $from) === -1) {
            \Router\refuse(409, "The parked {$ver} is OLDER than the core's {$from} — never derates, even from local.");
        }
        $to = $ver;
        $packetPath = LOCAL_PACKET;
    } else {
        $ann = announce();
        if ($ann['latest'] === null) {
            \Router\refuse(502, (string)$ann['error']);
        }
        $latest = (string)$ann['latest'];
        if (version_compare(ltrim($latest, 'v'), $from) !== 1) {
            \Router\refuse(409, "The core is already on {$from} — GitHub's newest version is {$latest}.");
        }
        $to = ltrim($latest, 'v'); // the announced word without the tag's vanity prefix
        $scratch = scratchDir();
        $packet = (string)($ann['packet'] ?? PACKET_URL . $latest); // the release's asset zip when it ships one
        $bytes = httpGet($packet);
        if ($bytes === null) {
            partWith($scratch);
            \Router\refuse(502, "The {$latest} packet would not leave the depot — the update is off.");
        }
        $packetPath = $scratch . '/packet.zip';
        if (@file_put_contents($packetPath, $bytes) === false || strlen($bytes) === 0) {
            partWith($scratch);
            \Router\refuse(500, 'The packet could not be held — the cache dir may be unwritable.');
        }
    }
    $scratch = $scratch ?? scratchDir(); // the local ride stages its tomb on demand

    $lock = @fopen(__DIR__ . '/cache/core.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            @fclose($lock);
            @unlink(__DIR__ . '/cache/core.lock');
        }
        \Router\refuse(409, 'One knife at a time — a core update is already on the table.');
    }

    $marker = false; // did WE hold the window open?
    register_shutdown_function(static function () use (&$marker, $lock): void {
        // the marker never outlives the request — success or hard stop alike
        if ($marker) {
            blankoOff();
        }
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        @unlink(__DIR__ . '/cache/core.lock'); // the knife rest leaves no name
    });

    [$staged, $entries, $stagedBytes] = stageCore($packetPath, $scratch);
    if ($staged === false) {
        partWith($scratch);
        \Router\refuse(502, 'The packet would not open — is the zip machinery intact?');
    }
    if ($staged === null || !is_string($staged)) {
        partWith($scratch);
        \Router\refuse(502, "The packet carries no core land (or beyond the roofs: {$entries} entries, {$stagedBytes} bytes) — the update is off.");
    }

    // did the packet carry the dashboard? read it BEFORE the swap — the
    // wholesale law renames the staged build/ into place, and a post-swap
    // peek would stare at an empty closet
    $top = '';
    foreach ((array)glob($staged . '/*', GLOB_ONLYDIR) ?: [] as $candidate) {
        $top = (string)$candidate;
        break;
    }
    $dashboard = $top !== '' && is_file($top . '/build/index.html');

    // === the knife is out — the site eats the blank plate from here on ===
    blankoOn();
    $marker = true;

    [$files, $pagetypes, $error] = swap($staged, $scratch);
    if ($error !== null) {
        partWith($scratch);
        \Router\refuse(500, (string)$error); // the rollback rode inside swap(); the shutdown ends the window
    }

    // bytecode may outlive a swap on hosts that pin validate_timestamps=0
    if (function_exists('opcache_invalidate')) {
        foreach ($files as $path) {
            @opcache_invalidate((string)$path, true);
        }
        @opcache_invalidate(VERSION_FILE, true);
    }
    partWith($scratch);
    $doneTo = version();
    if ($source === 'local') {
        @unlink(LOCAL_PACKET); // the ride is over — a local packet is consumed
    }
    $repair = $source === 'local' && version_compare($doneTo, $from) === 0 ? ' — a repair ride, same version' : '';
    return [
        'ok' => true,
        'from' => $from,
        'to' => $doneTo, // the floor's own word after the ride
        'announced' => $to, // what the source promised — a packet without the version file moves code, not the floor
        'source' => $source,
        'entries' => count($files),
        'newPagetypes' => $pagetypes,
        'dashboard' => $dashboard, // the face moved with the body?
        'note' => "The core is on {$doneTo} now" . $repair
            . ($pagetypes > 0 ? " — {$pagetypes} new pagetype shells added" : '')
            . ($dashboard ? '' : ' — but the packet carried no dashboard; a release asset zip (scripts/ship-release.sh) moves the face too') . '.',
    ];
}
