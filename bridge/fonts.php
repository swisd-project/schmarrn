<?php

/**
 * 1. Relative path: bridge/fonts.php
 * 2. Description: THE FONT-PACK STATION (2026-11-15, user order): the editor
 *                 bakes only the core Noto Sans subsets (latin + the scripts
 *                 Google's default sheet carries — bridge/assets/noto/, 8
 *                 woff2 files); every OTHER writing system rides on demand.
 *                 An admin pulls a pack from the catalog (Google's css2 sheet
 *                 + its woff2 subsets) onto the host; the pack's @font-face
 *                 blocks join the generated noto.css, and the editor's next
 *                 load wears them. The browser downloads a subset ONLY when
 *                 page text uses its unicode-range — the client never takes
 *                 more than it needs.
 * 3. Expects: bridge/assets/noto/noto-core.css (the committed seed) and
 *             noto.css (generated = core + packs). State in
 *             bridge/config/fonts.local.php (a LOCAL config — never core).
 * 4. Provides: Fonts\listAll/install/remove (+ regen). Admin-only at the door
 *              (bridge/index.php routes); every pack write is lidded.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

declare(strict_types=1);

namespace Fonts;

const DIR = __DIR__ . '/assets/noto';
const SEED = DIR . '/noto-core.css';
const SHEET = DIR . '/noto.css';
const LOCAL = __DIR__ . '/config/fonts.local.php';

/** The catalog: the packs an admin may pull. `family` is the css2 query name,
 *  `kb` an honest size hint (the CJK packs are whales — the modal says so). */
const CATALOG = [
    'arabic'     => ['label' => 'Arabic',     'family' => 'Noto Sans Arabic',    'kb' => 120],
    'armenian'   => ['label' => 'Armenian',   'family' => 'Noto Sans Armenian',  'kb' => 60],
    'devanagari' => ['label' => 'Devanagari', 'family' => 'Noto Sans Devanagari','kb' => 90],
    'ethiopic'   => ['label' => 'Ethiopic',   'family' => 'Noto Sans Ethiopic',  'kb' => 60],
    'georgian'   => ['label' => 'Georgian',   'family' => 'Noto Sans Georgian',  'kb' => 70],
    'hebrew'     => ['label' => 'Hebrew',     'family' => 'Noto Sans Hebrew',    'kb' => 100],
    'khmer'      => ['label' => 'Khmer',      'family' => 'Noto Sans Khmer',     'kb' => 130],
    'myanmar'    => ['label' => 'Myanmar',    'family' => 'Noto Sans Myanmar',   'kb' => 90],
    'tamil'      => ['label' => 'Tamil',      'family' => 'Noto Sans Tamil',     'kb' => 90],
    'thai'       => ['label' => 'Thai',       'family' => 'Noto Sans Thai',      'kb' => 90],
    'jp'         => ['label' => 'Japanese',   'family' => 'Noto Sans JP',        'kb' => 5000],
    'sc'         => ['label' => 'Simplified Chinese', 'family' => 'Noto Sans SC', 'kb' => 4500],
    'tc'         => ['label' => 'Traditional Chinese','family' => 'Noto Sans TC', 'kb' => 4800],
    'kr'         => ['label' => 'Korean',     'family' => 'Noto Sans KR',        'kb' => 5000],
];

/** The per-pack roof (audit-law shape, U4): a pack is count- and byte-capped —
 *  a mad sheet never exhausts the disk mid-install. */
const PACK_FILE_CAP = 400;
const PACK_BYTE_CAP = 32 * 1024 * 1024;

/** Google answers a browser UA with the woff2 unicode-range sheet; anything
 *  else gets full TTFs — the wrong format for this ride. */
const BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36';

/** The installed packs: ['packs' => [key => ['css' => …, 'files' => […]]]]. */
function local(): array
{
    $data = is_file(LOCAL) ? include LOCAL : [];
    $packs = is_array($data['packs'] ?? null) ? $data['packs'] : [];
    return ['packs' => $packs];
}

function saveLocal(array $data): void
{
    file_put_contents(LOCAL, "<?php\n\nreturn " . var_export($data, true) . ";\n");
}

/** The generated sheet: the committed seed + every installed pack's blocks. */
function regen(): void
{
    $css = (string)file_get_contents(SEED);
    foreach (local()['packs'] as $pack) {
        if (is_string($pack['css'] ?? null)) {
            $css .= "\n" . $pack['css'];
        }
    }
    file_put_contents(SHEET, $css);
}

/** The door's view: what the modal shows. */
function listAll(): array
{
    $installed = array_keys(local()['packs']);
    $available = [];
    foreach (CATALOG as $key => $pack) {
        if (!in_array($key, $installed, true)) {
            $available[] = ['pack' => $key, 'label' => $pack['label'], 'kb' => $pack['kb']];
        }
    }
    return [
        'baked' => ['pack' => 'core', 'label' => 'Core (latin, greek, cyrillic)', 'kb' => 476],
        'installed' => array_map(fn($k) => ['pack' => $k, 'label' => CATALOG[$k]['label'], 'kb' => CATALOG[$k]['kb']], $installed),
        'available' => $available,
    ];
}

/** Pull a pack onto the host: the css2 sheet, then its woff2 subsets. */
function install(string $key): void
{
    $pack = CATALOG[$key] ?? null;
    if ($pack === null) {
        \Router\refuse(400, "No such pack in the catalog: {$key}");
    }
    $css = httpGet('https://fonts.googleapis.com/css2?family=' . str_replace(' ', '+', $pack['family']) . ':wght@400;700&display=swap');
    if ($css === null || substr_count($css, '@font-face') === 0) {
        \Router\refuse(502, "The depot went dark — no font sheet for {$pack['label']}.");
    }
    // the urls must all be gstatic woff2 — nothing else rides home
    preg_match_all('#https://fonts\.gstatic\.com/[^)\s]+\.woff2#', (string)$css, $m);
    $urls = array_values(array_unique($m[0]));
    if ($urls === [] || count($urls) > PACK_FILE_CAP) {
        \Router\refuse(502, "The sheet for {$pack['label']} names no sane file set.");
    }
    $files = [];
    $total = 0;
    foreach ($urls as $url) {
        $file = 'p-' . $key . '-' . basename($url);
        $bytes = fetchTo(DIR . '/' . $file, $url);
        if ($bytes === null) {
            foreach ($files as $gone) {
                @unlink(DIR . '/' . $gone);
            }
            \Router\refuse(502, "The depot went dark mid-pack — {$pack['label']} pulled back.");
        }
        $total += $bytes;
        if ($total > PACK_BYTE_CAP) {
            \Router\refuse(502, "{$pack['label']} jumps the byte roof — not baked.");
        }
        $files[] = $file;
    }
    // the css rides with LOCAL urls only
    foreach ($urls as $i => $url) {
        $css = str_replace($url, '/bridge/assets/noto/' . $files[$i], $css);
    }
    $state = local();
    $state['packs'][$key] = ['css' => $css, 'files' => $files];
    saveLocal($state);
    regen();
}

/** A pack leaves: its files go, the sheet regenerates without it. */
function remove(string $key): void
{
    $state = local();
    if (!isset($state['packs'][$key])) {
        \Router\refuse(400, "No pack installed: {$key}");
    }
    foreach ($state['packs'][$key]['files'] ?? [] as $file) {
        if (is_string($file) && str_starts_with($file, 'p-' . $key . '-')) {
            @unlink(DIR . '/' . $file); // the prefix is the lid: only the pack's own files
        }
    }
    unset($state['packs'][$key]);
    saveLocal($state);
    regen();
}

/** The css2 sheet with a browser's voice. */
function httpGet(string $url): ?string
{
    $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => BROWSER_UA],
        'https' => ['timeout' => 30, 'user_agent' => BROWSER_UA]]);
    $bytes = @file_get_contents($url, false, $context);
    return $bytes === false ? null : (string)$bytes;
}

/** Stream a woff2 under the byte roof (mirrors Core\fetchPacket's law). */
function fetchTo(string $dest, string $url): ?int
{
    $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => BROWSER_UA],
        'https' => ['timeout' => 30, 'user_agent' => BROWSER_UA]]);
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
        $chunk = fread($src, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $total += strlen($chunk);
        fwrite($dst, $chunk);
        if ($total > PACK_BYTE_CAP) {
            fclose($src); fclose($dst); @unlink($dest);
            return null;
        }
    }
    fclose($src);
    fclose($dst);
    return $total > 0 ? $total : null;
}
