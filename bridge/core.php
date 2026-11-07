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
const TAGS_URL = 'https://api.github.com/repos/swisd-project/schmarrn/tags?per_page=100';
const PACKET_URL = 'https://codeload.github.com/swisd-project/schmarrn/zip/refs/tags/';
const VERSION_FILE = __DIR__ . '/CORE-VERSION';
const DASHBOARD_FILES = ['../build/DASHBOARD-VERSION', '../_/DASHBOARD-VERSION']; // repo layout, host layout
const BLANK_FILE = __DIR__ . '/cache/core-blank';
const BLANK_TTL = 300; // a marker older than the window is dust, not a knife (audit U5)
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

/** Stream a URL into a file UNDER THE BYTE ROOF (audit U4): a mad or
 *  malicious packet can no longer exhaust memory mid-download — the cap is
 *  enforced mid-flight, the partial never reaches the shelf. null when the
 *  depot is dark, the disk refuses, the stream dies, or the roof is jumped. */
function fetchPacket(string $url, string $dest): ?int
{
    $context = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'schmarrn-core/1.0'],
        'https' => ['timeout' => 20, 'user_agent' => 'schmarrn-core/1.0']]);
    $src = @fopen($url, 'rb', false, $context);
    if ($src === false) {
        return null;
    }
    $dst = @fopen($dest, 'wb');
    if ($dst === false) {
        @fclose($src);
        return null;
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
            @unlink($dest); // too heavy — it never lands
            return null;
        }
        if (@fwrite($dst, $chunk) !== strlen($chunk)) {
            @fclose($src);
            @fclose($dst);
            @unlink($dest);
            return null;
        }
    }
    @fclose($src);
    @fclose($dst);
    return $total > 0 ? $total : null; // an empty packet is no packet
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
 * The DASHBOARD's own version (2026-11-07): the face is not the floor — a rig
 * can carry core 1.2.8 under a 1.2.5-era dashboard (the half-ride wound). The
 * ship script stamps build/DASHBOARD-VERSION; unmarked builds answer null and
 * count as stale, so the repair ride is always offered to a lagging face.
 */
function dashboardVersion(): ?string
{
    foreach (DASHBOARD_FILES as $rel) {
        $raw = trim((string)@file_get_contents(__DIR__ . '/' . $rel));
        if ($raw !== '' && preg_match('/^\d+(?:\.\d+){0,3}$/', $raw) === 1) {
            return $raw;
        }
    }
    return null;
}

/** The one update verdict, computed ONCE, server-side: may this core move?
 *  newer floor → yes; equal floor with a lagging (or unmarked) face → the
 *  repair ride; older → never. Takes the announce in hand when the caller
 *  already fetched it (GET /bridge/core asks both — the memo saves the
 *  second GitHub round-trip; the unauthenticated budget is 60/h). */
function updatable(?array $ann = null): bool
{
    $ann ??= announce();
    if ($ann['latest'] === null) {
        return false;
    }
    $latest = ltrim((string)$ann['latest'], 'v');
    $floor = version();
    if (version_compare($latest, $floor) === 1) {
        return true;
    }
    if (version_compare($latest, $floor) === 0) {
        $face = dashboardVersion();
        return $face === null || version_compare($latest, $face) === 1;
    }
    return false;
}

/**
 * The release's own packet URL: its zip asset — the only place a built
 * dashboard (build/) can travel in. A schmarrn-named zip wins; any other zip
 * is the floor; none → null (the codeload zipball takes over).
 */
function assetIn(array $release): ?string
{
    $anyZip = null;
    $named = null;
    foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $a) {
        $name = is_string($a['name'] ?? null) ? strtolower((string)$a['name']) : '';
        $url = is_string($a['browser_download_url'] ?? null) ? (string)$a['browser_download_url'] : '';
        if ($url === '' || !str_ends_with($name, '.zip')) {
            continue; // not a packet — checksums, sigs, notes stay out
        }
        if ($named === null && str_contains($name, 'schmarrn')) {
            $named = $url; // the FIRST schmarrn-named zip wins (audit U6) — deterministic
        }
        if ($anyZip === null) {
            $anyZip = $url;
        }
    }
    return $named ?? $anyZip; // a schmarrn zip carries the face; any other zip is the floor
}

/**
 * The newest version the updater sees — the SEMVER MAX over the RELEASES
 * list, never `releases/latest` alone (that endpoint is creation-ordered;
 * it also hides nothing: a version without a release does not exist to the
 * update line — the v1.2.5 lesson, 2026-11-07: a tag cut without its release
 * made rigs claim versions GitHub couldn't serve). The packet rides the
 * winning release's zip ASSET when it ships one. The TAG LIST answers only
 * when no release exists at all (the pre-release era fallback). latest null
 * = the source is dark.
 */
function announce(): array
{
    $tag = null;
    $asset = null;
    // the releases list, PAGED (audit U7): the semver max of a 100+ release
    // history may live past page one. Walk FULL pages only — a short page is
    // the definitive end of the line; ten pages is the safety rail.
    $releasesBase = str_replace('/releases/latest', '/releases?per_page=100', RELEASES_URL);
    for ($page = 1; $page <= 10; $page++) {
        $raw = httpGet($releasesBase . '&page=' . $page);
        if ($raw === null) {
            break; // the depot went dark — what we hold is what we hold
        }
        $list = json_decode((string)$raw, true);
        if (!is_array($list) || $list === []) {
            break;
        }
        foreach ($list as $release) {
            if (!is_array($release) || !is_string($release['tag_name'] ?? null)) {
                continue;
            }
            $name = (string)$release['tag_name'];
            if (preg_match('/^v?(\d+(?:\.\d+){0,3})$/', $name) !== 1) {
                continue; // junk in the release hat — not a version
            }
            if ($tag === null || version_compare(ltrim($name, 'v'), ltrim($tag, 'v')) === 1) {
                $tag = $name;
                $asset = assetIn($release); // the winner's own asset, or null
            }
        }
        if (count($list) < 100) {
            break; // the last page — the whole history is in hand
        }
    }

    if ($tag === null) {
        // no releases at all — the pre-release era: the tag list may still answer
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

/** Recursively dust a directory the swap just hung — packet content, never a
 *  user's land; the restore law uses it to take a wholesale dir back out. */
function dustDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_merge((array)glob($dir . '/*'), (array)glob($dir . '/.*')) as $item) {
        $item = (string)$item;
        if (basename($item) === '.' || basename($item) === '..') {
            continue;
        }
        is_dir($item) ? dustDir($item) : @unlink($item);
    }
    @rmdir($dir);
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
    $backups = []; // repo-relative FILE => the old's scratch place
    $dirBackups = []; // wholesale DIR => the old dir's scratch place (null when none existed)
    $files = []; // every swapped target — the opcache drops walk this
    // THE TOTAL ROLLBACK (audit U-CLEAN): files AND wholesale dirs alike
    // register in the restore scope — "one break rolls everything back" is
    // the law this swap answers to, dirs included. The old vaults rest in
    // the scratch until the restore or the success owns them.
    $restore = static function () use (&$backups, &$dirBackups, $root): void {
        foreach (array_reverse(array_keys($backups)) as $rel) {
            $target = $root . '/' . $rel;
            $old = (string)$backups[$rel];
            if (is_file($target)) {
                @unlink($target);
            }
            @rename($old, $target);
        }
        foreach (array_reverse(array_keys($dirBackups)) as $dir) {
            $target = $root . '/' . $dir;
            $old = $dirBackups[$dir];
            if (is_dir($target)) {
                dustDir($target); // the new dir hung — packet dust, never user land
            }
            if (is_dir((string)$old)) {
                @rename((string)$old, $target); // the old face returns whole
            }
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
        $hadOld = is_dir($target);
        if ($hadOld && !@rename($target, $old)) {
            $restore();
            return [[], 0, "the old '{$dir}' would not move — the swap is off, nothing lost"];
        }
        if (!@rename($src, $target)) {
            if ($hadOld) {
                @rename($old, $target);
            }
            $restore();
            return [[], 0, "the new '{$dir}' would not hang — the swap is off, nothing lost"];
        }
        // the swap STOOD — the rollback owns it now (files and dirs, one law)
        $dirBackups[$dir] = $hadOld ? $old : null;
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
    if (!is_file(BLANK_FILE)) {
        return false;
    }
    // a marker older than the window is dust, not a knife (audit U5): a hard
    // kill mid-update must not blank the site until a hand climbs FTP. The
    // sweep happens at the read — and the read is everywhere the plate serves.
    if (time() - (int)@filemtime(BLANK_FILE) > BLANK_TTL) {
        @unlink(BLANK_FILE);
        return false;
    }
    return true;
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
        $latestBare = ltrim($latest, 'v');
        // the gate: strictly newer rides; an EQUAL floor rides as the REPAIR
        // when the face lags (the half-ride ban guarantees the packet carries
        // the dashboard) — older never rides, from either house
        $face = dashboardVersion();
        $repair = version_compare($latestBare, $from) === 0
            && ($face === null || version_compare($latestBare, $face) === 1);
        if (version_compare($latestBare, $from) !== 1 && !$repair) {
            \Router\refuse(409, "The core is already on {$from} — the newest RELEASED version is {$latest}." . (version_compare($latestBare, $from) === -1 ? ' The floor outran the release line — cut the release for the version this host carries.' : ''));
        }
        $to = ltrim($latest, 'v'); // the announced word without the tag's vanity prefix
        $scratch = scratchDir();
        $packet = (string)($ann['packet'] ?? PACKET_URL . $latest); // the release's asset zip when it ships one
        $packetPath = $scratch . '/packet.zip';
        // streamed UNDER THE BYTE ROOF (audit U4) — the cap is enforced
        // mid-flight; a mad asset can no longer exhaust memory mid-update
        $bytes = fetchPacket($packet, $packetPath);
        if ($bytes === null) {
            partWith($scratch);
            \Router\refuse(502, "The {$latest} packet would not come whole — the depot is dark, the cache refused, or it outweighs the " . BYTE_CAP . "-byte roof.");
        }
        // THE PACKET TRUTH GATE (audit U1): the packet's own CORE-VERSION is
        // the truth, the announce is the advertisement — the same
        // manifest-over-shelf law the wares pipeline answers to. A stale or
        // mislabeled asset on a fresh release derates no floor on this watch.
        $packetVer = peekPacket($packetPath);
        if ($packetVer === null || version_compare($packetVer, $to) !== 0) {
            partWith($scratch);
            \Router\refuse(502, "The {$latest} packet does not confess its own version"
                . ($packetVer === null ? ' — no readable bridge/CORE-VERSION in it' : " (it says {$packetVer})")
                . ' — attach the asset built from that tag and ride again.');
        }
    }
    $scratch = $scratch ?? scratchDir(); // the local ride stages its tomb on demand

    $lock = @fopen(__DIR__ . '/cache/core.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            @fclose($lock);
            // NEVER unlink the lock file (audit U2): a refused waiter that
            // deletes the file launders the mutex out from under the holder —
            // the next arrival flocks a fresh inode and a SECOND update rides
            // beside the first. The knife file stays: zero-byte dust in a
            // writable cache, harmless and honest.
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
        // the lock FILE stays by the same law (audit U2) — unlinking it in
        // the window between the release and the next waiter's flock hands
        // two knives to two cooks. The name outlives the blade; that is the
        // honest price of a mutex that cannot be laundered.
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
    // peek would stare at an empty closet. A flat staging (no top dir)
    // reads from its own root — the same tolerance swap() carries (audit U8).
    $top = $staged;
    foreach ((array)glob($staged . '/*', GLOB_ONLYDIR) ?: [] as $candidate) {
        $top = (string)$candidate;
        break;
    }
    $dashboard = is_file($top . '/build/index.html');

    // THE HALF-RIDE BAN (2026-11-07, the law the books carried and the code
    // failed to enforce — the rig rode 1.2.7 → 1.2.8 dashboard-less and the
    // operator paid for it): the floor does NOT move without the face. A
    // packet without the dashboard is refused before the knife ever leaves
    // the drawer — no blanko, no swap, no half-state. The repair is the
    // release's asset zip (scripts/ship-release.sh), attached or parked.
    if (!$dashboard) {
        partWith($scratch);
        \Router\refuse(409, 'The packet carries no dashboard — the floor does not move without the face. '
            . 'Attach the release asset zip (scripts/ship-release.sh) to the release on GitHub — or park it here — and ride again.');
    }

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
        'dashboard' => $dashboard, // always true on success — the half-ride ban refuses the rest
        'note' => "The core is on {$doneTo} now" . $repair
            . ($pagetypes > 0 ? " — {$pagetypes} new pagetype shells added" : '') . '.',
    ];
}
